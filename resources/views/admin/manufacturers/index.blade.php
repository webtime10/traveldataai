@extends('admin.layouts.layout')

@section('content')
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h1>{{ $pageTitle }}</h1></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="{{ route('admin.index') }}">Home</a></li>
                        <li class="breadcrumb-item active">Сайт</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Список сайтов</h3>
                    <a href="{{ route('admin.manufacturers.create') }}" class="btn btn-primary btn-sm float-right">
                        <i class="fas fa-plus"></i> Добавить
                    </a>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-bordered table-hover table-striped mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th class="text-center" style="width: 90px;">ID</th>
                                <th>Название</th>
                                <th class="text-center" style="width: 120px;">Порядок</th>
                                <th class="text-right" style="width: 180px;">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($manufacturers as $m)
                                <tr>
                                    <td class="text-center align-middle">{{ $m->id }}</td>
                                    <td class="align-middle">{{ $m->name }}</td>
                                    <td class="text-center align-middle">{{ (int) $m->sort_order }}</td>
                                    <td class="text-right align-middle">
                                        <a href="{{ route('admin.manufacturers.edit', $m->id) }}" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-pen"></i> Изм.
                                        </a>
                                        <form action="{{ route('admin.manufacturers.destroy', $m->id) }}" method="post" class="d-inline" onsubmit="return confirm('Удалить?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i> Удал.
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">Нет записей</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $manufacturers->links() }}</div>
            </div>
        </div>
    </section>
@endsection
