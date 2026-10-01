<?php

declare(strict_types=1);

namespace App\Domain\Groups\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to a group (ФО §6.5): personal (names the person) or by link (a token with a term and a use limit).
 *
 * @property int $id
 * @property int $group_id
 * @property int|null $person_id
 * @property string|null $token_hash
 * @property int|null $max_uses
 * @property int $uses
 * @property Carbon|null $expires_at
 * @property string $status
 * @property int|null $invited_by_person_id
 * @property Carbon|null $answered_at
 * @property-read Group $group
 * @property-read Person|null $person
 * @property-read Person|null $inviter
 */
class GroupInvitation extends Model
{
    public const string PENDING = 'pending';

    public const string ACCEPTED = 'accepted';

    public const string DECLINED = 'declined';

    public const string REVOKED = 'revoked';

    protected $attributes = ['status' => self::PENDING, 'uses' => 0];

    protected $fillable = ['group_id', 'person_id', 'token_hash', 'max_uses', 'uses', 'expires_at', 'status', 'invited_by_person_id', 'answered_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isLink(): bool
    {
        return $this->token_hash !== null;
    }

    public function isUsable(): bool
    {
        return $this->status === self::PENDING
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses < $this->max_uses);
    }

    /**
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'invited_by_person_id');
    }
}
