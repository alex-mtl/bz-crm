<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One impersonation session (Д-19): who acted as whom, why, and until when.
 *
 * @property int $id
 * @property int $impersonator_user_id
 * @property int $target_user_id
 * @property string $reason
 * @property Carbon $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $end_reason
 * @property string|null $ip_address
 * @property-read User $impersonator
 * @property-read User $target
 */
class Impersonation extends Model
{
    public const string END_STOPPED = 'stopped';

    public const string END_EXPIRED = 'expired';

    public const string END_SIGNED_OUT = 'signed_out';

    public const string END_REVOKED = 'revoked';

    protected $fillable = ['impersonator_user_id', 'target_user_id', 'reason', 'started_at', 'expires_at', 'ended_at', 'end_reason', 'ip_address'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function minutesLeft(): int
    {
        return max(0, (int) ceil(now()->diffInSeconds($this->expires_at, false) / 60));
    }
}
