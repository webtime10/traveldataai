<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    public const SOURCE_TEXT_FIELDS = [
        'source_text1',
        'source_text2',
        'source_text3',
        'source_text4',
        'source_text5',
        'source_text6',
        'source_text7',
        'source_text8',
    ];

    /**
     * Поля, которые можно массово заполнять через create()/update().
     * source_text1 … source_text8 — сырьё для AI‑генерации описаний товара.
     */
    protected $fillable = [
        'model',
        'sku',
        'image',
        'manufacturer_id',
        'author_id',
        'source_text1',
        'source_text2',
        'source_text3',
        'source_text4',
        'source_text5',
        'source_text6',
        'source_text7',
        'source_text8',
        'result',
        'result_source_sha1',
        'ai_status',
        'wp_published_once',
        'wp_last_published_at',
        'status',
    ];

    /**
     * Приведение типов полей модели.
     * status хранится в БД как int/tinyint, но в коде будет работать как boolean.
     */
    protected $casts = [
        'status' => 'boolean',
        'wp_published_once' => 'boolean',
        'wp_last_published_at' => 'datetime',
    ];

    /**
     * Настройки моделей этапа 1: колонки ai_*_model в product_descriptions (одинаковы на всех языках).
     *
     * @return array<string, string> ai_field => model key
     */
    public function descriptionModelSettings(): array
    {
        $descriptions = $this->relationLoaded('descriptions')
            ? $this->descriptions
            : $this->descriptions()->get();

        if ($descriptions->isEmpty()) {
            return [];
        }

        $out = [];
        foreach (ProductDescription::aiFieldKeys() as $field) {
            $column = \App\Support\AiDescriptionModelChoice::modelColumnForField($field);
            foreach ($descriptions as $desc) {
                $value = trim((string) ($desc->{$column} ?? ''));
                if ($value !== '') {
                    $out[$field] = $value;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Записать модель этапа 1 для поля во все языковые строки product_descriptions.
     */
    public function setDescriptionModelForField(string $aiField, string $modelKey): void
    {
        $column = \App\Support\AiDescriptionModelChoice::modelColumnForField($aiField);
        ProductDescription::query()
            ->where('product_id', $this->id)
            ->update([$column => $modelKey]);
    }

    /**
     * @param  array<string, string>  $fieldToModel
     */
    public function syncDescriptionModelSettings(array $fieldToModel): void
    {
        $normalized = \App\Support\AiDescriptionModelChoice::normalize(
            $fieldToModel,
            ProductDescription::aiFieldKeys()
        );

        $payload = [];
        foreach ($normalized as $field => $modelKey) {
            $payload[\App\Support\AiDescriptionModelChoice::modelColumnForField($field)] = $modelKey;
        }

        if ($payload === []) {
            return;
        }

        ProductDescription::query()
            ->where('product_id', $this->id)
            ->update($payload);
    }

    /**
     * Производитель товара.
     */
    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    /**
     * Автор (пользователь системы), который создал/отредактировал товар.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Все локализованные описания товара (по разным языкам).
     */
    public function descriptions(): HasMany
    {
        return $this->hasMany(ProductDescription::class);
    }

    /**
     * Категории, к которым принадлежит товар (связь многие‑ко‑многим).
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    public static function wrapSourceSegment(int $id, ?string $text): string
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }

        return '<source id="'.$id.'">'."\n".$text."\n".'</source>';
    }

    public static function unwrapSourceSegment(?string $stored, ?int $expectedId = null): string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return '';
        }

        if (preg_match('/<source\s+id=["\']?(\d+)["\']?\s*>(.*?)<\/source>/is', $stored, $match)) {
            if ($expectedId === null || (int) $match[1] === $expectedId) {
                return trim($match[2]);
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, string|null>  $segments  source_text1 … source_text8 (plain text)
     */
    public static function combineSourceSegments(array $segments): string
    {
        $parts = [];
        foreach (self::SOURCE_TEXT_FIELDS as $index => $field) {
            $plain = self::unwrapSourceSegment($segments[$field] ?? '', $index + 1);
            if ($plain === '') {
                continue;
            }
            $parts[] = self::wrapSourceSegment($index + 1, $plain);
        }

        return implode("\n\n", $parts);
    }

    public function sourceSegmentPlain(string $field): string
    {
        $index = array_search($field, self::SOURCE_TEXT_FIELDS, true);

        return self::unwrapSourceSegment(
            $this->{$field} ?? '',
            $index !== false ? $index + 1 : null
        );
    }

    /** Общее сырьё для AI: непустые колонки в XML <source id="N">. */
    public function combinedSourceText(): string
    {
        $segments = [];
        foreach (self::SOURCE_TEXT_FIELDS as $field) {
            $segments[$field] = $this->{$field};
        }

        return self::combineSourceSegments($segments);
    }
}
