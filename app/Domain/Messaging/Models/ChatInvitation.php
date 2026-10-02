<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation link to a group chat (ФО §6.6.2): limited in time and in the number of uses. Only the hash of
 * its token is stored.
 *
 * @property int $id
 * @property int $chat_id
 * @property string $token_hash
 * @property int|null $max_uses
 * @property int $uses
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $created_by_person_id
 * @property-read Chat $chat
 */
class ChatInvitation extends Model
{
    protected $fillable = ['chat_id', 'token_hash', 'max_uses', 'uses', 'expires_at', 'revoked_at', 'created_by_person_id'];

    protected $hidden = ['token_hash'];

    protected $attributes = ['uses' => 0];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<Chat, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->uses >= $this->max_uses;
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ! $this->isExpired() && ! $this->isExhausted();
    }
}
