{{-- 
    Редактирование товара (админ): форма productForm + отдельные AJAX для AI.
    Генерация контента:
      • Кнопка «Сгенерировать» не сабмитит форму; вызывает ProductController@generateAi → Bus::chain в очереди
        (соединение queue.* в config, часто database на dev).
      • Маршруты: admin.products.generate_ai, check_ai_status, update_description_models, extract_text.
    Выбор модели этапа 1 сохраняется в product_descriptions.<ai_field>_model (один ключ на все языки товара).
    Светофоры у полей: опрос GET check_ai_status; выжимка — отдельный подобъёт JSON extraction в том же ответе.
--}}
@extends('admin.layouts.layout')

@section('content')
    {{-- Хедер страницы: заголовок и хлебные крошки --}}
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>{{ $pageTitle }}</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.products.index') }}">Посты</a></li>
                        <li class="breadcrumb-item active">Редактирование</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            {{-- Основная карточка с формой редактирования поста --}}
            <div class="card">
                <div class="card-header">
                    <button
                        type="button"
                        id="btn-wp-publish"
                        class="btn btn-primary btn-sm"
                        data-product-id="{{ $product->id }}"
                        title="Publish to WordPress"
                        aria-label="Publish to WordPress"
                    >
                        <i class="fab fa-wordpress"></i> WordPress
                    </button>
                    <button
                        type="button"
                        id="btn-wp-republish"
                        class="btn btn-primary btn-sm ml-2 d-none"
                        data-product-id="{{ $product->id }}"
                        title="Republish to WordPress"
                        aria-label="Republish to WordPress"
                    >
                        <i class="fab fa-wordpress"></i> Ещё раз опубликовать
                    </button>
                    <div class="card-tools">
                        <a href="{{ route('admin.products.index') }}" class="btn btn-default btn-sm">
                            <i class="fas fa-reply"></i> Назад
                        </a>
                        <button type="submit" form="productForm" class="btn btn-primary btn-sm" title="Сохранить" aria-label="Сохранить">
                            <i class="fas fa-save"></i> Сохранить
                        </button>
                    </div>
                </div>
                {{-- 
                    Основная форма редактирования поста.
                    - отправляется на маршрут admin.products.update (метод PUT в ProductController@update);
                    - НЕ отвечает за запуск AI — за это отвечает AJAX‑скрипт ниже.
                --}}
                <form id="productForm" action="{{ route('admin.products.update', $product->id) }}" method="post" novalidate>
                    @csrf @method('PUT')
                    <div class="card-body">
                        @if ($errors->any())
                            <div class="alert alert-danger mb-3">{{ $errors->first() }}</div>
                        @endif

                        {{-- 
                            Выбранные категории поста.
                            - берём либо значения из old() (если форма вернулась с ошибками),
                              либо текущие категории поста из связи $product->categories.
                        --}}
                        @php $sel = old('category_ids', $product->categories->pluck('id')->all()); @endphp
                        <div class="form-group">
                            <label for="category_ids">Категория <span class="text-danger">*</span></label>
                            <select name="category_ids[]" id="category_ids" class="form-control" required>
                                <option value="">— Выберите категорию —</option>
                                @foreach($categories as $cat)
                                    @php $d = $defaultLanguage ? $cat->descriptions->firstWhere('language_id', $defaultLanguage->id) : null; @endphp
                                    <option value="{{ $cat->id }}" {{ in_array($cat->id, $sel) ? 'selected' : '' }}>
                                        {{ $d->name ?? '#'.$cat->id }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        {{-- Блок параметров поста: модель и сайт --}}
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="model">Model <span class="text-danger">*</span></label>
                                    <input type="text" name="model" id="model" class="form-control" value="{{ old('model', $product->model) }}" required maxlength="64">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="manufacturer_id">Сайт <span class="text-danger">*</span></label>
                                    <select name="manufacturer_id" id="manufacturer_id" class="form-control" required>
                                        <option value="">—</option>
                                        @foreach($manufacturers as $m)
                                            <option value="{{ $m->id }}" {{ old('manufacturer_id', $product->manufacturer_id) == $m->id ? 'selected' : '' }}>{{ $m->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        {{-- Цена / Количество / Порядок скрыты из формы. Колонки сохранены в БД. --}}
                        {{-- Картинка (путь) скрыта из формы. Колонка сохранена в БД. --}}

                        {{-- 
                            Вкладки с локализованными описаниями.
                            Для каждого языка есть своя вкладка и свой набор полей:
                            - name_<код>
                            - slug_<код>
                            - description_<код>
                            Сами данные берутся из связей $product->descriptions и значений old().
                        --}}
                        <h5>Описания</h5>
                        {{--
                            ЭТАП 1 МОДЕЛИ ИИ — краткая схема:
                            • Выбор здесь задаёт только «кто пишет текст» для этапа description (батч-пайплайн).
                              Этапы 2–3 (Flash) в UI не настраиваются.
                            • Один ключ модели на ai_* поле для ВСЕХ языков вкладок; клики синхронизируются JS.
                            • Выбор пишется в product_descriptions.*_model (клик radio → AJAX; «Сгенерировать» и batch — тоже).
                            • При открытии edit галка снова из БД ($descriptionModelDefaults).
                            • При «Сгенерировать»: цепочка ProductController@generateAi → очередь
                              ExtractProductGistJob → GenerateAiDescriptionsBatchJob → DispatchAiFieldGenerationJobs.

                            Якорь внизу: #ai-description-models — таблица и статус сохранения.
                        --}}
                        <p class="mb-2">
                            <a href="#ai-description-models" class="small">
                                <i class="fas fa-arrow-down"></i> Модели этапа 1 (сохраняются в БД при выборе)
                            </a>
                        </p>

                        {{--
                            Скрытый блок: «реальные» radio для имени поля description_models[ai_faq].
                            Видимые копии с классом ai-description-model-radio дублируют выбор между вкладками.
                            AJAX generate_ai добавляет ключи именно из этих input (collectDescriptionModels).
                        --}}
                        <div id="description-models-inputs" class="d-none" aria-hidden="true">
                            @foreach($aiFields as $fieldKey => $fieldLabel)
                                @php $defaultModel = $descriptionModelDefaults[$fieldKey] ?? 'gemini-flash'; @endphp
                                @foreach($descriptionModelChoices as $modelKey => $modelLabel)
                                    <input
                                        type="radio"
                                        class="ai-description-model-real"
                                        name="description_models[{{ $fieldKey }}]"
                                        value="{{ $modelKey }}"
                                        {{ $defaultModel === $modelKey ? 'checked' : '' }}
                                    >
                                @endforeach
                            @endforeach
                        </div>

                        <ul class="nav nav-tabs" role="tablist">
                            @foreach($languages as $i => $language)
                                <li class="nav-item">
                                    <a class="nav-link {{ $i === 0 ? 'active' : '' }}" data-toggle="tab" href="#lang{{ $language->id }}">{{ $language->name }}</a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="tab-content border p-3">
                            @foreach($languages as $i => $language)
                                @php
                                    $c = $language->code;
                                    $desc = $product->descriptions->firstWhere('language_id', $language->id);
                                @endphp
                                <div class="tab-pane fade {{ $i === 0 ? 'show active' : '' }}" id="lang{{ $language->id }}">
                                    <div class="form-group">
                                       
                                        <label for="name_{{ $c }}">Название <span class="text-danger">*</span></label>
                                        <input type="text" name="name_{{ $c }}" id="name_{{ $c }}" class="form-control" value="{{ old('name_'.$c, $desc->name ?? '') }}" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="slug_{{ $c }}">Slug <span class="text-danger">*</span></label>
                                        <input type="text" name="slug_{{ $c }}" id="slug_{{ $c }}" class="form-control" value="{{ old('slug_'.$c, $desc->slug ?? '') }}"
                                               data-slug-locked="{{ ($desc && ($desc->slug ?? '') !== '') ? '1' : '0' }}" autocomplete="off" required>
                                        <small class="form-text text-muted">Пустой — из названия. Очистите поле, чтобы снова подтягивать из названия.</small>
                                    </div>
                                   <!-- <div class="form-group">
                                        <label>Описание</label>
                                        <textarea name="description_{{ $c }}" class="form-control" rows="4">{{ old('description_'.$c, $desc->description ?? '') }}</textarea>
                                    </div> -->
                                    {{-- AI-блоки: для каждого языка свой textarea контента; один выбор модели на всё дерево вкладок. --}}
                                    @foreach($aiFields as $fieldKey => $fieldLabel)
                                    @php $defaultModel = $descriptionModelDefaults[$fieldKey] ?? 'gemini-flash'; @endphp
                                    <div class="form-group ai-field-block" data-field="{{ $fieldKey }}">
                                        <div class="wrap-ai-status-indicator d-flex flex-wrap align-items-center mb-2">
        <div class="d-flex align-items-center mr-2">
            <input type="checkbox"
                   class="mr-1 ai-field-select-checkbox d-none"
                   data-field="{{ $fieldKey }}"
                   title="В поштучном режиме: генерировать это поле">
            <label class="mb-0 font-weight-bold">{{ $fieldLabel }}</label>
        </div>
                                            <div class="ai-description-model-picker d-flex flex-wrap align-items-center">
                                                @foreach($descriptionModelChoices as $modelKey => $modelLabel)
                                                    <label class="mb-0 mr-3 small font-weight-normal">
                                                        <input
                                                            type="radio"
                                                            class="ai-description-model-radio"
                                                            name="description_models_visible[{{ $fieldKey }}][{{ $language->id }}]"
                                                            data-field="{{ $fieldKey }}"
                                                            value="{{ $modelKey }}"
                                                            {{ $defaultModel === $modelKey ? 'checked' : '' }}
                                                        >
                                                        {{ $modelLabel }}
                                                    </label>
                                                @endforeach
                                            </div>
                                            <a href="javascript:void(0)" class="ai-status-indicator ml-auto" data-field="{{ $fieldKey }}">
                                                <i class="fas fa-circle text-secondary"></i>
                                            </a>
                                            <button
                                                type="button"
                                                class="btn btn-xs btn-primary ml-2 d-none wp-field-publish-btn"
                                                data-field="{{ $fieldKey }}"
                                                title="Обновить этот блок в WordPress"
                                            >
                                                <i class="fab fa-wordpress"></i>
                                            </button>
                                        </div>
                                        <textarea
                                            name="{{ $fieldKey }}_{{ $c }}"
                                            class="form-control"
                                            rows="18"
                                        >{!! old($fieldKey.'_'.$c, $desc->{$fieldKey} ?? '') !!}</textarea>
                                    </div>
                                @endforeach
                                </div>
                            @endforeach
                        </div>

                        {{-- 
                            Сырьё source_text1..8 → products; при generateAi сначала выжимка (ExtractProductGistJob → result),
                            затем batch этапа 1 читает выжимку; этапы 2–3 без UI выбора модели (Flash в воркере).
                            Индикатор «Выжимка» — объект res.extraction из checkAiStatus.
                            #btn-generate-ai: AJAX POST generate_ai + appendDescriptionModelsToPostData (ключи из скрытых radio).
                            Очередь: настроена в config queue (не обязательно Redis).
                        --}}
                        <div class="form-group mt-3 p-3" style="background: #e6f0ff; border-radius: 8px; border: 1px solid #b3c6ff;">
                            <h5 class="mt-0 mb-3"><i class="fas fa-file-alt"></i> Исходное сырьё для AI (1–8)</h5>
                            @foreach(\App\Models\Product::SOURCE_TEXT_FIELDS as $i => $field)
                                @php $num = $i + 1; @endphp
                                <div class="form-group mb-3 p-2" style="background:#f5f8ff;border-radius:6px;border:1px solid #c5d4ff;" data-field="{{ $field }}">
                                    <label for="{{ $field }}">Исходное сырьё {{ $num }}</label>
                                    <div class="mb-2 d-flex flex-wrap align-items-center gap-2 small">
                                        <input type="file"
                                               id="{{ $field }}_file"
                                               class="d-none product-source-file-input"
                                               accept=".pdf,.docx,.txt,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain"
                                               data-target="{{ $field }}">
                                        <button type="button" class="btn btn-outline-primary btn-sm product-source-attach-btn" data-target="{{ $field }}" title="PDF, DOCX, TXT">
                                            <i class="fas fa-paperclip"></i> Прикрепить файл
                                        </button>
                                        <span class="text-muted product-source-status small" data-target="{{ $field }}"></span>
                                    </div>
                                    <textarea
                                        name="{{ $field }}"
                                        id="{{ $field }}"
                                        class="form-control product-source-textarea"
                                        rows="4"
                                        style="background:#fff;border-color:#0644ff;"
                                        placeholder="Сырьё {{ $num }}…"
                                    >{{ old($field, $product->sourceSegmentPlain($field)) }}</textarea>
                                </div>
                            @endforeach
                            <div class="wrap-ai-status-indicator d-flex align-items-center mt-2">
                                <label class="mb-0 mr-2">Выжимка сырья</label>
                                <a href="javascript:void(0)" class="ai-extraction-status-indicator">
                                    <i class="fas fa-circle text-secondary"></i>
                                </a>
                                <span id="ai-extraction-status-text" class="text-muted ml-2 small">Ожидание</span>
                                <span id="ai-extraction-ready-hint" class="badge badge-success ml-2 d-none">Выжимка готова</span>
                            </div>
                            <textarea
                                name="result"
                                id="result_textarea"
                                class="form-control"
                                rows="6"
                                style="display:none;background:#eef4ff;border-color:#b3c6ff;"
                                placeholder="Буфер для итогового текста (например, результат AI)..."
                            >{{ old('result', $product->result) }}</textarea>
                            
                            <div class="mt-2">
                                <div class="btn-group mr-3" role="group" aria-label="Режим генерации">
                                    <button type="button"
                                            id="btn-ai-mode-batch"
                                            class="btn btn-sm btn-primary active"
                                            title="Обычный режим: выжимка + все AI‑поля">
                                        Общий
                                    </button>
                                    <button type="button"
                                            id="btn-ai-mode-per-field"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Генерировать только отмеченные галочками поля">
                                        Поштучно
                                    </button>
                                </div>
                                <div id="ai-rebuild-extraction-wrap" class="form-check form-check-inline align-middle mr-3 d-none">
                                    <input class="form-check-input" type="checkbox" id="ai-rebuild-extraction">
                                    <label class="form-check-label small" for="ai-rebuild-extraction">
                                        Собрать выжимку (result)
                                    </label>
                                </div>
                                <div id="ai-extraction-model-wrap" class="form-group form-group-sm d-inline-block mb-0 mr-3 align-middle">
                                    <select id="ai-extraction-model" class="form-control form-control-sm">
                                        <option value="gemini-flash" selected>Выжимка: Gemini Flash</option>
                                        <option value="openai-gpt-4o-mini">Выжимка: GPT-4o mini</option>
                                    </select>
                                </div>
                                {{-- Кнопка запуска AI‑генерации. НЕ отправляет форму, а вызывает AJAX‑запрос ниже. --}}
                                <button type="button" id="btn-generate-ai" class="btn btn-success">
                                    <i class="fas fa-robot"></i>
                                    <span id="btn-generate-ai-text">Сгенерировать контент для всех языков</span>
                                </button>
                                {{-- Индикатор загрузки, показывается на время AJAX‑запроса к серверу. --}}
                                <div id="ai-loader" style="display:none;" class="ml-2 d-inline-block">
                                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                    <span id="ai-loader-message" class="text-primary ml-1">Робот думает над переводом...</span>
                                </div>
                            </div>
                        </div>

                        {{-- Связка AJAX: см. блок <script>: saveDescriptionModelsUrl. Сводное отображение label по descriptionModelLabels. --}}
                        <div id="ai-description-models" class="form-group mt-4 p-3 border rounded bg-light">
                            <h5 class="mb-2"><i class="fas fa-microchip"></i> Модели этапа 1 (description)</h5>
                            <p class="text-muted small mb-2">
                                Клик по radio сохраняет модель в колонки <code>product_descriptions.*_model</code>
                                (напр. <code>ai_faq_model</code>) для всех языков, товар #{{ $product->id }}.
                                «Сгенерировать» использует эти настройки. Этапы 2–3 — Gemini Flash.
                            </p>
                            <p id="description-models-save-status" class="small mb-3 text-secondary">—</p>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0 bg-white">
                                    <thead class="thead-light">
                                        <tr><th>Поле</th><th>Модель (этап 1)</th></tr>
                                    </thead>
                                    <tbody id="description-models-summary">
                                        @foreach($aiFields as $fieldKey => $fieldLabel)
                                            @php
                                                $mk = $descriptionModelDefaults[$fieldKey] ?? 'gemini-flash';
                                                $ml = $descriptionModelChoices[$mk] ?? $mk;
                                            @endphp
                                            <tr data-field="{{ $fieldKey }}">
                                                <td>{{ $fieldLabel }}</td>
                                                <td class="description-models-summary-value">{{ $ml }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {{-- Переключатель "Активен" — статус поста (boolean поле в модели Product) --}}
                        <div class="form-group mt-3">
                            <input type="hidden" name="status" value="0">
                            <label><input type="checkbox" name="status" value="1" {{ old('status', $product->status) ? 'checked' : '' }}> Активен</label>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    {{-- Подключаем общий JS‑фрагмент, который синхронизирует name/slug между собой. --}}
    @include('admin.partials.slug-auto-sync')

    {{--
        =====================================================================
        CLIENT-SIDE: AI генерация и статусы на edit.blade.php
        =====================================================================
        Маршруты (префикс admin уже в группе; route(..., [], false) — относительный URL, смежный с тем же протоколом/host):
          • POST admin.products.generate_ai      → ProductController@generateAi
              Кладёт в очередь цепочку: выжимка → batch description по моделям → джобы этапов 2–3.
          • GET  admin.products.check_ai_status → ProductController@checkAiStatus
              JSON: extraction + fields[*] — индикаторы выжимки и каждого ai_-поля по языкам.
          • POST admin.products.extract_text    → текст из файла PDF/DOCX/TXT в textarea сырья
          • POST admin.products.update_description_models → сохранить одну связку field + model в БД

        Переменные из Blade:
          • sourceFields — Product::SOURCE_TEXT_FIELDS (сырьё 1–8)
          • aiFieldKeys  — ключи $aiFields (совпадают с именами колонок AI)
          • expectedLanguages / checkUrl — для опроса готовности
          • extractTextUrl, saveDescriptionModelsUrl — см. переменные в $(function ниже).
    --}}
    @php
        $aiCheckExpectedLanguages = collect($languages ?? [])
            ->pluck('code')
            ->map(fn ($code) => strtolower((string) $code))
            ->filter()
            ->values()
            ->all();
        if ($aiCheckExpectedLanguages === []) {
            $aiCheckExpectedLanguages = \App\Models\Language::codesForAiChecks();
        }
    @endphp
    <script>
    /*
     * Основная логика на этой странице (jQuery при DOM ready):
     *  1. Сырьё source_text1..8 → collectSourcePayload() → отправка вместе с generate_ai.
     *  2. Модели этапа 1 — двойной слой радиокнопок + sync со скрытыми input для POST.
     *  3. Сохранение выбора в БД — saveDescriptionModelToServer без перезагрузки страницы.
     *  4. Светофоры — опрос checkAiStatus (при загрузке и каждые 5 с после запуска генерации).
     *  5. WordPress — отдельный обработчик #btn-wp-publish внизу файла при наличии.
     */
    
    $(function () {
        /** Поля таблицы products: сырьё для выжимки и последующего batch по всем языкам. */
        var sourceFields = @json(\App\Models\Product::SOURCE_TEXT_FIELDS);
        var generationDoneAlertShown = false;
        var sourceFileReadMsg = 'Читаю файл, подождите...';
        /** ProductController@extractText — вставляет извлечённый текст в соответствующий textarea. */
        var extractTextUrl = "{{ route('admin.products.extract_text', [], false) }}";
        /** ProductController@updateDescriptionModels — пишет product_descriptions.<field>_model для всех language_id этого товара. */
        var saveDescriptionModelsUrl = "{{ route('admin.products.update_description_models', $product->id, false) }}";
        /** Отображение читаемого названия модели в сводке (ключ → «Gemini Flash» и т.д.). */
        var descriptionModelLabels = @json($descriptionModelChoices);

        /** Сериализация сырья для POST generate-ai (ключи совпадают с именами полей в контроллере). */
        function collectSourcePayload() {
            var data = {};
            sourceFields.forEach(function (field) {
                data[field] = $('#' + field).val();
            });
            return data;
        }

        /**
         * Синхронизация двух типов радиокнопок одного поля:
         *  - скрытые .ai-description-model-real (единый name="" для отправки моделей при generate-ai)
         *  - видимые на каждой вкладке .ai-description-model-radio (data-field=имя_ai_поля)
         */
        function syncDescriptionModelRadios(field, value) {
            if (!field || !value) {
                return;
            }
            document.querySelectorAll(
                'input.ai-description-model-real[name="description_models[' + field + ']"]'
            ).forEach(function (el) {
                el.checked = (el.value === value);
            });
            document.querySelectorAll(
                'input.ai-description-model-radio[data-field="' + field + '"]'
            ).forEach(function (el) {
                el.checked = (el.value === value);
            });
        }

        /** Снимок ключей описания моделей как плоские ключи массива в POST (multipart не используется). */
        function collectDescriptionModels() {
            var models = {};
            aiFieldKeys.forEach(function (field) {
                var value = $('input.ai-description-model-real[name="description_models[' + field + ']"]:checked').val();
                if (value) {
                    models[field] = value;
                }
            });
            return models;
        }

        /** Дублирует collectDescriptionModels() в объект postData для $.ajax(generate_ai). */
        function appendDescriptionModelsToPostData(postData) {
            var models = collectDescriptionModels();
            Object.keys(models).forEach(function (field) {
                postData['description_models[' + field + ']'] = models[field];
            });
        }

        function hasAnySourceText() {
            var payload = collectSourcePayload();
            for (var i = 0; i < sourceFields.length; i++) {
                if ($.trim(payload[sourceFields[i]] || '') !== '') {
                    return true;
                }
            }
            return false;
        }

        function uploadFileToField(field, file) {
            if (!file) {
                return;
            }
            var $ta = $('#' + field);
            var previousVal = $ta.val();
            $ta.prop('disabled', true).val(sourceFileReadMsg);
            $('.product-source-status[data-target="' + field + '"]').text('Обработка файла…');

            var formData = new FormData();
            formData.append('file', file);

            $.ajax({
                url: extractTextUrl,
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                data: formData,
                processData: false,
                contentType: false
            })
                .done(function (res) {
                    var chunk = (res && res.text) ? res.text : '';
                    var sep = (previousVal && !/\n$/.test(previousVal)) ? '\n\n' : (previousVal ? '\n' : '');
                    $ta.prop('disabled', false).val(previousVal + sep + chunk);
                    $('.product-source-status[data-target="' + field + '"]').text(
                        chunk ? 'Текст добавлен.' : 'Файл пустой или текст не извлечён.'
                    );
                })
                .fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : ('Ошибка ' + (xhr.status || ''));
                    $ta.prop('disabled', false).val(previousVal);
                    $('.product-source-status[data-target="' + field + '"]').text('');
                    alert(msg);
                });
        }

        $('.product-source-attach-btn').on('click', function () {
            var field = $(this).data('target');
            $('#' + field + '_file').trigger('click');
        });

        $('.product-source-file-input').on('change', function () {
            var fileInput = this;
            var field = $(fileInput).data('target');
            var file = fileInput.files && fileInput.files[0];
            uploadFileToField(field, file);
            fileInput.value = '';
        });

        /** Ключи AI-полей с сервера (совпадают с ProductDescription::$fillable частью ai_*). */
        var aiFieldKeys = @json(array_keys($aiFields));

        /** После загрузки/смены вкладки Bootstrap 4 «tab shown» подтягиваем состояние из скрытых radio. */
        function initDescriptionModelRadiosFromHidden() {
            aiFieldKeys.forEach(function (field) {
                var checked = document.querySelector(
                    'input.ai-description-model-real[name="description_models[' + field + ']"]:checked'
                );
                if (checked && checked.value) {
                    syncDescriptionModelRadios(field, checked.value);
                }
            });
        }

        /** Одна строка сводной таблицы #description-models-summary. */
        function updateDescriptionModelsSummary(field, modelKey) {
            var label = descriptionModelLabels[modelKey] || modelKey;
            $('#description-models-summary tr[data-field="' + field + '"] .description-models-summary-value').text(label);
        }

        /** После любого восстановления hidden-state обновляет все строки таблицы. */
        function refreshDescriptionModelsSummary() {
            aiFieldKeys.forEach(function (field) {
                var checked = document.querySelector(
                    'input.ai-description-model-real[name="description_models[' + field + ']"]:checked'
                );
                if (checked && checked.value) {
                    updateDescriptionModelsSummary(field, checked.value);
                }
            });
        }

        /** Моментально фиксирует выбор в БД без «Сохранить» главной формы; CSRF через meta/токен. */
        function saveDescriptionModelToServer(field, modelKey) {
            var $status = $('#description-models-save-status');
            $status.removeClass('text-success text-danger').addClass('text-secondary').text('Сохраняю…');
            $.ajax({
                url: saveDescriptionModelsUrl,
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                data: { field: field, model: modelKey }
            }).done(function () {
                $status.removeClass('text-danger').addClass('text-success').text('Сохранено в БД');
            }).fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : ('Ошибка ' + (xhr.status || ''));
                $status.removeClass('text-success').addClass('text-danger').text(msg);
            });
        }

        /** Первичная выравненность UI по скрытым input (ответ сервера с edit). */
        initDescriptionModelRadiosFromHidden();
        refreshDescriptionModelsSummary();
        $('#description-models-save-status').text('Настройки загружены из БД');

        /** Клик по видимой модели: внутри вкладки группирует name; sync копирует выбор на все языки + скрытые input. */
        $(document).on('click', 'input.ai-description-model-radio', function () {
            var field = this.getAttribute('data-field');
            var value = this.value;
            if (!field || !value) {
                return;
            }
            syncDescriptionModelRadios(field, value);
            updateDescriptionModelsSummary(field, value);
            saveDescriptionModelToServer(field, value);
        });

        /* Переключение языка может отрисовать копию radio — переинициализируем checked из источника. */
        $('a[data-toggle="tab"]').on('shown.bs.tab', function () {
            initDescriptionModelRadiosFromHidden();
        });

        /** Светофоры по каждому AI-полю; селектор подставляет data-field для иконки. */
        var indicatorSelector = '.ai-status-indicator[data-field="%FIELD%"] i';
        /** Ядро статусной полилинии: см. докблок секции Blade выше. */
        var checkUrl = "{{ route('admin.products.check_ai_status', $product->id, false) }}";
        var expectedLanguages = @json($aiCheckExpectedLanguages);
        var $extractionIcon = $('.ai-extraction-status-indicator i');
        var $extractionText = $('#ai-extraction-status-text');
        var lastExtractionStatus = 'idle';

        function isExtractionReady() {
            return lastExtractionStatus === 'success';
        }

        function hasUsableGist() {
            return $.trim($('#result_textarea').val() || '') !== '';
        }

        function setAiLoaderMessage(text) {
            $('#ai-loader-message').text(text);
        }

        function updateExtractionReadyUi() {
            var isPerField = aiGenerationMode === 'per_field';
            var ready = isExtractionReady();
            if (isPerField && ready) {
                $('#ai-extraction-ready-hint').removeClass('d-none');
                $('#ai-rebuild-extraction-wrap').addClass('d-none');
                $('#ai-rebuild-extraction').prop('checked', false);
            } else {
                $('#ai-extraction-ready-hint').addClass('d-none');
                if (isPerField) {
                    $('#ai-rebuild-extraction-wrap').removeClass('d-none');
                }
            }
        }

        function setExtractionIndicatorState(status) {
            if (!$extractionIcon.length) {
                return;
            }
            if (status === 'success') {
                $extractionIcon.removeClass('text-warning text-danger text-secondary').addClass('text-success');
                $extractionText.removeClass('text-warning text-danger').addClass('text-muted').text('Готово');
            } else if (status === 'error') {
                $extractionIcon.removeClass('text-warning text-success text-secondary').addClass('text-danger');
                $extractionText.removeClass('text-muted text-warning').addClass('text-danger').text('Ошибка');
            } else if (status === 'processing') {
                $extractionIcon.removeClass('text-success text-danger text-secondary').addClass('text-warning');
                $extractionText.removeClass('text-muted text-danger').addClass('text-warning').text('Идёт выжимка...');
            } else {
                $extractionIcon.removeClass('text-warning text-success text-danger').addClass('text-secondary');
                $extractionText.removeClass('text-warning text-danger').addClass('text-muted').text('Ожидание');
            }
        }

        function setExtractionIndicatorFromPayload(payload) {
            var status = (payload && payload.status) ? payload.status : 'idle';
            lastExtractionStatus = status;
            var message = payload && payload.error_message ? payload.error_message : null;
            var hint = 'Ожидание выжимки сырья.';

            if (status === 'success') {
                hint = 'Выжимка готова и сохранена в result.';
            } else if (status === 'processing') {
                hint = 'Gemini делает общую выжимку сырья.';
            } else if (status === 'error') {
                hint = 'Ошибка выжимки' + (message ? ': ' + message : '.');
            }

            setExtractionIndicatorState(status);
            $('.ai-extraction-status-indicator')
                .attr('title', hint)
                .attr('data-original-title', hint);
            updateExtractionReadyUi();
        }

        function setFieldIndicatorState(field, status) {
            var $fieldIndicators = $(indicatorSelector.replace('%FIELD%', field));
            if (!$fieldIndicators.length) {
                return;
            }
            if (status === 'success') {
                $fieldIndicators.removeClass('text-warning text-danger text-secondary').addClass('text-success');
            } else if (status === 'error') {
                $fieldIndicators.removeClass('text-warning text-success text-secondary').addClass('text-danger');
            } else if (status === 'idle') {
                $fieldIndicators.removeClass('text-warning text-success text-danger').addClass('text-secondary');
            } else {
                $fieldIndicators.removeClass('text-success text-danger text-secondary').addClass('text-warning'); // processing
            }
        }

        function setFieldIndicatorHint(field, payload) {
            var $indicatorLink = $('.ai-status-indicator[data-field="' + field + '"]');
            if (!$indicatorLink.length) {
                return;
            }

            var status = (payload && payload.status) ? payload.status : 'idle';
            var missingLanguages = (payload && Array.isArray(payload.missing_languages)) ? payload.missing_languages : [];
            var errorReason = payload && payload.error_reason ? payload.error_reason : null;
            var errorReasonLabel = payload && payload.error_reason_label ? payload.error_reason_label : null;
            var hint = '';

            if (status === 'success') {
                hint = 'Готово: поле заполнено по всем языкам.';
            } else if (status === 'error') {
                var reasonText = errorReasonLabel || errorReason;
                hint = 'Ошибка генерации' + (reasonText ? ' (' + reasonText + ')' : '') + '.';
            } else if (status === 'processing') {
                if (missingLanguages.length > 0) {
                    hint = 'В процессе: нет данных для языков: ' + missingLanguages.join(', ');
                } else {
                    hint = 'В процессе генерации...';
                }
            } else {
                hint = 'Ожидание запуска.';
            }

            $indicatorLink.attr('title', hint);
            $indicatorLink.attr('data-original-title', hint);
        }

        function setAllIndicatorsState(status) {
            aiFieldKeys.forEach(function (field) {
                setFieldIndicatorState(field, status);
            });
        }

        function setSelectedFieldsIndicatorsState(fields, status) {
            fields.forEach(function (field) {
                setFieldIndicatorState(field, status);
            });
        }

        function setFieldIndicatorFromPayload(field, payload) {
            var status = (payload && payload.status) ? payload.status : 'idle';
            var startedAt = payload ? payload.started_at : null;
            var missingLanguages = (payload && Array.isArray(payload.missing_languages)) ? payload.missing_languages : [];
            var allLanguagesMissing = expectedLanguages.length > 0 && missingLanguages.length === expectedLanguages.length;

            // Серый только когда поле вообще не начато (нет старта и отсутствуют данные по всем языкам).
            if (status === 'processing' && !startedAt && allLanguagesMissing) {
                setFieldIndicatorState(field, 'idle');
                setFieldIndicatorHint(field, { status: 'idle' });
                return;
            }

            // Если часть языков уже заполнена, но не все — это процесс (желтый), не idle (серый).
            setFieldIndicatorState(field, status);
            setFieldIndicatorHint(field, payload || { status: status });
        }

        // Начальное состояние индикаторов до первого ответа checkAiStatus.
        setExtractionIndicatorState('idle');
        setAllIndicatorsState('idle');

        // Первичная подгрузка статусов из ProductController@checkAiStatus (очередь / кэш / БД для полей и выжимки).
        $.ajax({
            url: checkUrl,
            method: 'GET',
            data: {
                languages: expectedLanguages
            },
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        }).done(function (res) {
            if (res && res.extraction) {
                setExtractionIndicatorFromPayload(res.extraction);
            }
            if (res && res.fields) {
                aiFieldKeys.forEach(function (field) {
                    var fieldPayload = res.fields[field] || {};
                    setFieldIndicatorFromPayload(field, fieldPayload);
                });
            }
        });

        // Режим генерации: общий (batch) или поштучный (per_field).
        var aiGenerationMode = 'batch';

        function setPerFieldControlsVisibility() {
            var isPerField = aiGenerationMode === 'per_field';
            $('.ai-field-select-checkbox').toggleClass('d-none', !isPerField);
            if (!isPerField) {
                $('#ai-rebuild-extraction').prop('checked', false);
                $('#ai-extraction-ready-hint').addClass('d-none');
                $('#ai-rebuild-extraction-wrap').addClass('d-none');
            } else {
                updateExtractionReadyUi();
            }
        }

        $('#btn-ai-mode-batch').on('click', function () {
            aiGenerationMode = 'batch';
            $('#btn-ai-mode-batch').removeClass('btn-outline-primary').addClass('btn-primary active');
            $('#btn-ai-mode-per-field').removeClass('btn-primary active').addClass('btn-outline-primary');
            $('#btn-generate-ai-text').text('Сгенерировать контент для всех языков');
            setPerFieldControlsVisibility();
        });

        $('#btn-ai-mode-per-field').on('click', function () {
            aiGenerationMode = 'per_field';
            $('#btn-ai-mode-per-field').removeClass('btn-outline-primary').addClass('btn-primary active');
            $('#btn-ai-mode-batch').removeClass('btn-primary active').addClass('btn-outline-primary');
            $('#btn-generate-ai-text').text('Сгенерировать выбранные поля');
            setPerFieldControlsVisibility();
        });

        // По умолчанию режим общий — скрываем поштучные чекбоксы.
        setPerFieldControlsVisibility();

        $(document).on('change', '.ai-field-select-checkbox', function () {
            if (aiGenerationMode !== 'per_field' || !$(this).is(':checked')) {
                return;
            }
            if (!isExtractionReady() && !$('#ai-rebuild-extraction').is(':checked')) {
                $(this).prop('checked', false);
                alert('Сначала соберите выжимку или отметьте «Собрать выжимку» для запуска вместе с блоками.');
            }
        });

        // здесь основная логика передачи на пост
        // Клик по «Сгенерировать…» — старт генерации по выбранному режиму.
        $('#btn-generate-ai').on('click', function () {
            generationDoneAlertShown = false;

            var sourcePayload = collectSourcePayload();
            var resultText = $('#result_textarea').val();
            var $btn = $(this);
            var $loader = $('#ai-loader');

            var wantRebuildExtraction = $('#ai-rebuild-extraction').is(':checked');
            var extractionReady = isExtractionReady();
            var skipSourceForPerField = aiGenerationMode === 'per_field' && extractionReady && !wantRebuildExtraction;

            if (skipSourceForPerField) {
                if (!hasUsableGist()) {
                    alert('Выжимка в буфере пуста. Отметьте «Собрать выжимку» или заполните сырьё.');
                    return;
                }
            } else if (!hasAnySourceText()) {
                alert('Заполните хотя бы одно поле «Исходное сырьё» (1–8) или дождитесь готовой выжимки.');
                return;
            }

            var combinedLen = 0;
            if (!skipSourceForPerField) {
                sourceFields.forEach(function (field) {
                    combinedLen += (sourcePayload[field] || '').length;
                });
                if (combinedLen > 720000) {
                    alert('Сырьё слишком большое: ' + combinedLen + ' символов. Максимум: 720000 символов.');
                    return;
                }
                if (combinedLen > 240000 && !confirm('Сырьё большое (' + combinedLen + ' символов). Оно будет обработано частями и может занять больше времени. Продолжить?')) {
                    return;
                }
            }
            // Если в конфиге нет ai-полей — не отправляем запрос.
            if (!aiFieldKeys.length) {
                alert('Нет AI-полей для генерации.');
                return;
            }

            var selectedFields = [];
            if (aiGenerationMode === 'per_field') {
                $('.ai-field-select-checkbox:checked').each(function () {
                    var f = this.getAttribute('data-field');
                    if (f) {
                        selectedFields.push(f);
                    }
                });
                if (!selectedFields.length && !wantRebuildExtraction) {
                    alert('В поштучном режиме отметьте «Собрать выжимку» или выберите хотя бы одно AI‑поле.');
                    return;
                }
            }

            var extractionOnlyRun = aiGenerationMode === 'per_field' && wantRebuildExtraction && selectedFields.length === 0;

            // Подтверждение: формулировка зависит от режима.
            var confirmText;
            if (extractionOnlyRun) {
                confirmText = 'Будет собрана только выжимка сырья. AI‑поля не затрагиваются. Продолжить?';
            } else if (aiGenerationMode === 'per_field') {
                if (skipSourceForPerField) {
                    confirmText = 'Выжимка готова. Будут перезаписаны только выбранные AI‑поля (' + selectedFields.length + ' шт.) для всех языков. Продолжить?';
                } else {
                    confirmText = 'Будут перезаписаны только выбранные AI‑поля (' + selectedFields.length + ' шт.) для всех языков. Продолжить?';
                }
            } else {
                confirmText = 'Это перезапишет все AI-поля для всех языков. Продолжить?';
            }
            if (!confirm(confirmText)) {
                return;
            }

            // Первая блокировка UI до ответа generate_ai (защита от двойного клика).
            $btn.prop('disabled', true);
            if (extractionOnlyRun) {
                setAiLoaderMessage('Собираю выжимку...');
            } else if (skipSourceForPerField) {
                setAiLoaderMessage('Выжимка готова. Генерирую выбранные блоки...');
            } else if (wantRebuildExtraction || aiGenerationMode === 'batch') {
                setAiLoaderMessage('Собираю выжимку и генерирую поля...');
            } else {
                setAiLoaderMessage('Робот думает над переводом...');
            }
            $loader.show();

            if (extractionOnlyRun) {
                setExtractionIndicatorState('processing');
            } else if (skipSourceForPerField) {
                setExtractionIndicatorState('success');
                setSelectedFieldsIndicatorsState(selectedFields, 'processing');
            } else if (wantRebuildExtraction || aiGenerationMode === 'batch') {
                setExtractionIndicatorState('processing');
                if (aiGenerationMode === 'per_field') {
                    setSelectedFieldsIndicatorsState(selectedFields, 'processing');
                } else {
                    setAllIndicatorsState('processing');
                }
            } else {
                setSelectedFieldsIndicatorsState(selectedFields, 'processing');
            }

            $btn.prop('disabled', true);
            $loader.show();




        // Старт генерации: тот же урл, что в route('admin.products.generate_ai'); см. комментарии Blade над <script>.
            $.ajax({
               /*-- Относительный путь: POST уходит на тот же хост и схему (http/https), что и страница.
                     Иначе при APP_URL=https, а вход по http, route() даст https — cookie сессии не уйдёт → Unauthenticated. */
                url: "{{ route('admin.products.generate_ai', [], false) }}",
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                data: (function () {
                    var postData = Object.assign({
                        product_id: {{ $product->id }},
                        result_text: resultText
                    }, sourcePayload);

                    postData.mode = aiGenerationMode;
                    if (aiGenerationMode === 'per_field') {
                        postData['ai_fields'] = selectedFields;
                    }
                    if (wantRebuildExtraction) {
                        postData.rebuild_extraction = 1;
                    }
                    postData.extraction_model = $('#ai-extraction-model').val() || 'gemini-flash';

                    appendDescriptionModelsToPostData(postData);
                    return postData;
                })()
            })
                // УСПЕШНЫЙ ОТВЕТ СЕРВЕРА (HTTP 200, без ошибок в контроллере)
                .done(function (data) {
    // Ссылка на проверку (маршрут, который ты добавил в web.php)
// заходит в метод проверок и узнает состояние светафора

    // Опрос статуса: мгновенная первая проверка + регулярный polling.
    var pollAttempts = 0;
    var pollErrorStreak = 0;
    {{-- Интервал 5 с; держим опрос дольше server-side таймаута (config ai.generation.timeout_seconds) --}}
    var maxPollAttempts = {{ (int) ceil((int) config('ai.generation.timeout_seconds', 3600) / 5) + 120 }};
    var pollTimer = null;

    function stopPolling() {
        if (pollTimer !== null) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function requestGenerationStatus() {
        pollAttempts += 1;
        if (pollAttempts > maxPollAttempts) {
            stopPolling();
            setAllIndicatorsState('error');
            alert('Генерация не завершилась за отведённое время. Проверьте логи (очередь, OPENAI_API_KEY / php artisan config:clear).');
            $('#btn-generate-ai').prop('disabled', false);
            $('#ai-loader').hide();
            return;
        }

        $.ajax({
            url: checkUrl,
            method: 'GET',
            data: {
                languages: expectedLanguages
            },
            dataType: 'json',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            success: function (res) {
                pollErrorStreak = 0;
                if (res && res.extraction) {
                    setExtractionIndicatorFromPayload(res.extraction);
                }
                if (res && res.fields) {
                    aiFieldKeys.forEach(function(field) {
                        var fieldPayload = res.fields[field] || {};
                        setFieldIndicatorFromPayload(field, fieldPayload);
                    });
                }

                if (res && res.status === 'error') {
                    stopPolling();
                    var hintParts = [];
                    if (res.extraction && res.extraction.status === 'error') {
                        hintParts.push('Выжимка: ' + (res.extraction.error_message || res.extraction.error_reason || 'ошибка'));
                    }
                    if (res.fields) {
                        aiFieldKeys.forEach(function (f) {
                            var p = res.fields[f] || {};
                            if (p.status === 'error' && (p.error_reason_label || p.error_reason)) {
                                hintParts.push(f + ': ' + (p.error_reason_label || p.error_reason));
                            }
                        });
                    }
                    var hint = hintParts.length ? ('\n\n' + hintParts.join('\n')) : '';
                    var tmo = (typeof res.timeout_seconds !== 'undefined') ? res.timeout_seconds : '';
                    alert('Ошибка генерации (опрос статуса).' + hint
                        + (tmo !== '' ? '\n\nТаймаут сервера: ' + tmo + ' с (env AI_GENERATION_TIMEOUT / config ai.generation.timeout_seconds).' : '')
                        + '\n\nСм. также laravel.log и таблицу failed_jobs.');
                    $('#btn-generate-ai').prop('disabled', false);
                    $('#ai-loader').hide();
                    setAiLoaderMessage('Робот думает над переводом...');
                    return;
                }
                if (extractionOnlyRun && res && res.extraction) {
                    if (res.extraction.status === 'success') {
                        stopPolling();
                        $('#btn-generate-ai').prop('disabled', false);
                        $('#ai-loader').hide();
                        setAiLoaderMessage('Робот думает над переводом...');
                        if (!generationDoneAlertShown) {
                            generationDoneAlertShown = true;
                            alert('Выжимка готова. Отметьте нужные блоки и нажмите «Сгенерировать выбранные поля».');
                        }
                        return;
                    }
                    if (res.extraction.status === 'error') {
                        stopPolling();
                        $('#btn-generate-ai').prop('disabled', false);
                        $('#ai-loader').hide();
                        setAiLoaderMessage('Робот думает над переводом...');
                        alert('Ошибка выжимки: ' + (res.extraction.error_message || res.extraction.error_reason || 'неизвестная ошибка'));
                        return;
                    }
                }
                if (res && res.is_ready === true && res.status === 'success') {
                    setAllIndicatorsState('success');
                    $('#btn-generate-ai').prop('disabled', false);
                    $('#ai-loader').hide();
                    setAiLoaderMessage('Робот думает над переводом...');
                    stopPolling();
                    if (!generationDoneAlertShown) {
                        generationDoneAlertShown = true;
                        $('#generation-done-modal').modal('show');
                    }
                }
            },
            error: function (xhr) {
                pollErrorStreak += 1;
                if (pollErrorStreak < 3) {
                    return;
                }
                stopPolling();
                setAllIndicatorsState('error');
                alert('Ошибка проверки статуса: ' + xhr.status);
                $('#btn-generate-ai').prop('disabled', false);
                $('#ai-loader').hide();
                setAiLoaderMessage('Робот думает над переводом...');
            }
        });
    }

    requestGenerationStatus(); // первая проверка сразу, без ожидания 5 секунд
    pollTimer = setInterval(requestGenerationStatus, 5000); // интервал опроса статуса
})
                // ОТВЕТ С ОШИБКОЙ (например, валидация, 500, проблемы с Redis/OpenAI и т.п.)
                .fail(function (xhr) {
                    setAllIndicatorsState('error');
                    var msg = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : 'Ошибка сервера или лимиты API';
                    alert('Произошла ошибка: ' + msg);
                    $btn.prop('disabled', false);
                    $loader.hide();
                    setAiLoaderMessage('Робот думает над переводом...');
                });
        });
    });
 /*
написано так загружай загрузил файл, он отправился на сервер, Потом он вернулся. Уже переработано, если оно в доке или или даже не в доке обычный текст. И потом он загружается снова уже переработаны.  после чего и после чего оно отправляется уже в другой контроллер на обработку
в итоге на мервер предется сырьё, и результат + айди страницы

*/   
    </script>

    <div class="modal fade" id="generation-done-modal" tabindex="-1" role="dialog" aria-labelledby="generationDoneModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="generationDoneModalLabel">Готово</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Работа завершена: все поля заполнены.
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" id="generation-done-ok-btn">ok</button>
                </div>
            </div>
        </div>
    </div>

<script>
        /* отправка на вордрпрес */
$(function () {
    var wpPublishedOnce = @json((bool) ($product->wp_published_once ?? false));
    var wpButtonHtml = '<i class="fab fa-wordpress"></i> WordPress';
    var wpPublishedHtml = '<i class="fas fa-check"></i> Опубликовано';
    var wpRepublishDefaultHtml = '<i class="fab fa-wordpress"></i> Ещё раз опубликовать';
    var $publishBtn = $('#btn-wp-publish');
    var $republishBtn = $('#btn-wp-republish');
    var $fieldButtons = $('.wp-field-publish-btn');

    function isPerFieldMode() {
        return $('#btn-ai-mode-per-field').hasClass('active');
    }

    function refreshWpButtonsUi() {
        if (wpPublishedOnce) {
            $publishBtn
                .prop('disabled', true)
                .removeClass('btn-primary')
                .addClass('btn-success')
                .css('opacity', '0.65')
                .html(wpPublishedHtml);
            $republishBtn.removeClass('d-none');
        } else {
            $publishBtn
                .prop('disabled', false)
                .removeClass('btn-success')
                .addClass('btn-primary')
                .css('opacity', '')
                .html(wpButtonHtml);
            $republishBtn.addClass('d-none');
        }

        var showFieldButtons = wpPublishedOnce && isPerFieldMode();
        $fieldButtons.toggleClass('d-none', !showFieldButtons);
    }

    function callWpPublish(url, extraData, beforeSend, onDoneResetHtml, $buttonRef) {
        var $btn = $buttonRef;
        $btn.prop('disabled', true).html(beforeSend);

        $.ajax({
            url: url,
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            data: extraData || {}
        })
            .done(function (response) {
                wpPublishedOnce = true;
                refreshWpButtonsUi();
                alert(response.message || 'Published to WordPress.');
                $btn.prop('disabled', false).html(onDoneResetHtml);
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message)
                    ? xhr.responseJSON.message
                    : 'Unknown error';
                alert('Error: ' + msg);
                $btn.prop('disabled', false).html(onDoneResetHtml);
            });
    }

    $publishBtn.on('click', function () {
        callWpPublish(
            '{{ route("admin.products.publish_wordpress", ["product" => $product->id], false) }}',
            {},
            '<i class="fas fa-spinner fa-spin"></i> Sending...',
            wpPublishedOnce ? wpPublishedHtml : wpButtonHtml,
            $(this)
        );
    });

    $republishBtn.on('click', function () {
        callWpPublish(
            '{{ route("admin.products.publish_wordpress", ["product" => $product->id], false) }}',
            {},
            '<i class="fas fa-spinner fa-spin"></i> Republish...',
            wpRepublishDefaultHtml,
            $(this)
        );
    });

    $(document).on('click', '.wp-field-publish-btn', function () {
        var $btn = $(this);
        var field = String($btn.data('field') || '');
        if (!field) {
            return;
        }
        callWpPublish(
            '{{ route("admin.products.publish_wordpress_field", ["product" => $product->id], false) }}',
            { ai_field: field },
            '<i class="fas fa-spinner fa-spin"></i>',
            '<i class="fab fa-wordpress"></i>',
            $btn
        );
    });

    $('#btn-ai-mode-batch, #btn-ai-mode-per-field').on('click', function () {
        setTimeout(refreshWpButtonsUi, 0);
    });

    refreshWpButtonsUi();
});
</script>

<script>
$(function () {
    $('#generation-done-ok-btn').on('click', function () {
        location.reload();
    });
});
</script>

    @include('admin.partials.slug-auto-sync')
@endsection
