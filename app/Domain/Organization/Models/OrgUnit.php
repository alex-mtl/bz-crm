<?php

declare(strict_types=1);

namespace App\Domain\Organization\Models;

use App\Domain\Geo\Models\Territory;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A unit of the organization tree (ФО §7). Bound to 0..N territories (Д-3).
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $description
 * @property int|null $head_person_id
 * @property string $path
 * @property int $depth
 * @property int $sort_order
 * @property Carbon|null $archived_at
 * @property-read OrgUnit|null $parent
 * @property-read Person|null $head
 */
class OrgUnit extends Model
{
    protected $fillable = ['parent_id', 'name', 'description', 'head_person_id', 'path', 'depth', 'sort_order', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'depth' => 'integer'];
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<OrgUnit, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'head_person_id');
    }

    /**
     * @return BelongsToMany<Territory, $this>
     */
    public function territories(): BelongsToMany
    {
        return $this->belongsToMany(Territory::class, 'org_unit_territories')->withPivot(['assigned_by_user_id', 'assigned_at']);
    }

    /**
     * @return HasMany<OrgMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrgMembership::class);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * True if $other is this unit or one of its descendants.
     */
    public function covers(OrgUnit $other): bool
    {
        return $this->path !== '' && str_starts_with($other->path, $this->path);
    }

    /**
     * @param  Builder<OrgUnit>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<OrgUnit>  $query
     */
    public function scopeWithinPath(Builder $query, string $path): void
    {
        $query->where('path', 'like', $path.'%');
    }
}
