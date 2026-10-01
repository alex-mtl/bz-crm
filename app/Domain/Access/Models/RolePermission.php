<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\PermissionEffect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $role_id
 * @property string $permission_code
 * @property PermissionEffect $effect
 * @property string|null $data_scope null = assignment scope; own | related
 */
class RolePermission extends Model
{
    public $timestamps = false;

    protected $fillable = ['role_id', 'permission_code', 'effect', 'data_scope'];

    protected function casts(): array
    {
        return ['effect' => PermissionEffect::class];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
