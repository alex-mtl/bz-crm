<?php

declare(strict_types=1);

namespace App\Domain\Catalogs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $catalog_code
 * @property string $code
 * @property string $name_ro
 * @property string $name_ru
 * @property string $name_en
 * @property list<string>|null $unverified_locales
 * @property array<string, mixed>|null $properties
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort_order
 * @property int|null $merged_into_id
 */
class CatalogItem extends Model
{
    protected $fillable = [
        'catalog_code', 'code', 'name_ro', 'name_ru', 'name_en', 'unverified_locales',
        'properties', 'is_system', 'is_active', 'sort_order', 'merged_into_id',
    ];

    protected function casts(): array
    {
        return [
            'unverified_locales' => 'array',
            'properties' => 'array',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<CatalogItem>  $query
     */
    public function scopeOfCatalog(Builder $query, string $catalogCode): void
    {
        $query->where('catalog_code', $catalogCode)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Items that can be chosen for new records (deactivated ones stay on old records).
     *
     * @param  Builder<CatalogItem>  $query
     */
    public function scopeSelectable(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->getAttribute('name_'.$locale) ?? $this->name_ro);
    }

    public function property(string $key, mixed $default = null): mixed
    {
        return ($this->properties ?? [])[$key] ?? $default;
    }

    public function hasUnverifiedTranslation(): bool
    {
        return ($this->unverified_locales ?? []) !== [];
    }
}
