<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of the territory tree (Д-1, Д-7). Official names only are shown (Д-9);
 * traditional forms live in search_aliases and serve search only.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $level
 * @property string $code
 * @property string $name_ro
 * @property string $name_ru
 * @property string $name_en
 * @property list<string>|null $search_aliases
 * @property string|null $iso
 * @property int|null $geonameid
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $railway_station
 * @property string|null $description
 * @property string $path
 * @property int $depth
 * @property int $sort_order
 * @property bool $is_active
 * @property-read Territory|null $parent
 */
class Territory extends Model
{
    public const string COUNTRY = 'country';

    public const string MACRO_REGION = 'macro_region';

    public const string DISTRICT = 'district';

    public const string LOCALITY = 'locality';

    public const string SECTOR = 'sector';

    protected $fillable = [
        'parent_id', 'level', 'code', 'name_ro', 'name_ru', 'name_en', 'search_aliases', 'iso', 'geonameid',
        'latitude', 'longitude', 'railway_station', 'description', 'path', 'depth', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'search_aliases' => 'array',
            'railway_station' => 'boolean',
            'is_active' => 'boolean',
            'depth' => 'integer',
            'geonameid' => 'integer',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Territory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Territory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->getAttribute('name_'.$locale) ?? $this->name_ro);
    }

    /**
     * True if $other is this node or lies inside its subtree.
     */
    public function covers(Territory $other): bool
    {
        return $this->path !== '' && str_starts_with($other->path, $this->path);
    }

    /**
     * The node and its whole subtree.
     *
     * @param  Builder<Territory>  $query
     */
    public function scopeWithinPath(Builder $query, string $path): void
    {
        $query->where('path', 'like', $path.'%');
    }

    /**
     * Official names in any language plus traditional forms (Д-9).
     *
     * @param  Builder<Territory>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $query->where(fn (Builder $q) => $q
            ->where('name_ro', 'like', $like)
            ->orWhere('name_ru', 'like', $like)
            ->orWhere('name_en', 'like', $like)
            ->orWhere('search_aliases', 'like', $like));
    }

    /**
     * Ids of the ancestors from the root down, parsed from the path.
     *
     * @return list<int>
     */
    public function ancestorIds(): array
    {
        $ids = array_map('intval', array_values(array_filter(explode('/', $this->path))));
        array_pop($ids);

        return $ids;
    }
}
