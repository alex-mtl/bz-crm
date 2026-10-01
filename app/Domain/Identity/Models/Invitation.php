<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $person_type
 * @property string $token_hash
 * @property list<string> $role_codes
 * @property int $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_user_id
 * @property Carbon|null $revoked_at
 * @property int|null $person_id the existing card this invitation gives an account to (Д-22)
 */
class Invitation extends Model
{
    protected $fillable = [
        'email', 'first_name', 'last_name', 'person_type', 'token_hash', 'role_codes', 'invited_by_user_id',
        'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at', 'person_id',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'role_codes' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function state(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
