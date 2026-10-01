<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property string|null $scope_type
 * @property int|null $scope_id
 * @property int|null $granted_by_user_id
 * @property Carbon $granted_at
 * @property Carbon|null $expires_at
 * @property string $kind role | delegation
 * @property string|null $reason
 * @property Carbon|null $expiry_recorded_at
 * @property-read Role $role
 */
class UserRole extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'role_id', 'scope_type', 'scope_id', 'granted_by_user_id', 'granted_at', 'expires_at', 'kind', 'reason', 'expiry_recorded_at'];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'expires_at' => 'datetime', 'expiry_recorded_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @param  Builder<UserRole>  $query
     */
    public function scopeInEffect(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
