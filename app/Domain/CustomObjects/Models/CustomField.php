<?php

declare(strict_types=1);

namespace App\Domain\CustomObjects\Models;

use App\Support\Translation\HasTranslatedName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A field added by the administrator without code (ФО §5.1, §6.9.1). Phase 3: fields of a person card.
 *
 * @property int $id
 * @property string $entity
 * @property string $code
 * @property string $name_ro
 * @property string $name_ru
 * @property string $name_en
 * @property string $field_type
 * @property list<array{value: string, ro: string, ru: string, en: string}>|null $options
 * @property list<string>|null $applies_to
 * @property int $sort_order
 * @property bool $is_active
 */
class CustomField extends Model
{
    use HasTranslatedName;

    public const string PERSON = 'person';

    public const array TYPES = ['text', 'number', 'date', 'bool', 'select'];

    protected $fillable = ['entity', 'code', 'name_ro', 'name_ru', 'name_en', 'field_type', 'options', 'applies_to', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['options' => 'array', 'applies_to' => 'array', 'is_active' => 'boolean'];
    }

    /**
     * @param  Builder<CustomField>  $query
     */
    public function scopeFor(Builder $query, string $entity): void
    {
        $query->where('entity', $entity)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Whether the field is shown for this kind of record (e.g. a person type); null = for all.
     */
    public function appliesTo(?string $kind): bool
    {
        return $this->applies_to === null || $this->applies_to === [] || in_array($kind, $this->applies_to, true);
    }

    /**
     * @return array<string, string> value => label
     */
    public function optionLabels(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $labels = [];
        foreach ($this->options ?? [] as $option) {
            $labels[$option['value']] = $option[$locale] ?? $option['ro'];
        }

        return $labels;
    }
}
