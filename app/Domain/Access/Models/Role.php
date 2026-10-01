<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name_ro
 * @property string $name_ru
 * @property string $name_en
 * @property list<string>|null $unverified_locales
 * @property bool $is_system
 * @property string|null $description
 * @property int|null $inherits_role_id
 * @property-read Role|null $parentRole
 * @property-read Collection<int, RolePermission> $permissions
 */
class Role extends Model
{
    protected $fillable = ['code', 'name_ro', 'name_ru', 'name_en', 'unverified_locales', 'is_system', 'description', 'inherits_role_id'];

    protected function casts(): array
    {
        return ['unverified_locales' => 'array', 'is_system' => 'boolean'];
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * Role inheritance (ФО §6.2): a role gets all permissions of the role it inherits from.
     *
     * @return BelongsTo<Role, $this>
     */
    public function parentRole(): BelongsTo
    {
        return $this->belongsTo(self::class, 'inherits_role_id');
    }

    public function name(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->getAttribute('name_'.$locale) ?? $this->name_ro);
    }
}
