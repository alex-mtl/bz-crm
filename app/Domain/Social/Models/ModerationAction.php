<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a moderator did (ФО §6.4.4): hid or restored content, warned or muted a person. A mute ends by itself at expires_at.
 *
 * @property int $id
 * @property string $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property int|null $person_id
 * @property int|null $moderator_user_id
 * @property string|null $reason
 * @property Carbon|null $expires_at
 * @property Carbon|null $lifted_at
 * @property Carbon $created_at
 */
class ModerationAction extends Model
{
    public const string HIDE = 'hide';

    public const string RESTORE = 'restore';

    public const string WARN = 'warn';

    public const string MUTE = 'mute';

    public const string UNMUTE = 'unmute';

    public const null UPDATED_AT = null;

    protected $table = 'moderation_actions';

    protected $fillable = ['action', 'target_type', 'target_id', 'person_id', 'moderator_user_id', 'reason', 'expires_at', 'lifted_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'lifted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_user_id');
    }

    public function isActiveMute(): bool
    {
        return $this->action === self::MUTE && $this->lifted_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
