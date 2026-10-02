<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person in a chat: their role there, what they want to be notified about, how far they have read, and the
 * draft they have not sent yet. The two message pointers are the read state of the chat (ТЗ §20 "MessageRead").
 *
 * @property int $id
 * @property int $chat_id
 * @property int $person_id
 * @property string $role
 * @property string $notify
 * @property int|null $last_read_message_id
 * @property int|null $last_delivered_message_id
 * @property string|null $draft
 * @property Carbon $joined_at
 * @property-read Person $person
 */
class ChatMember extends Model
{
    public const string OWNER = 'owner';

    public const string ADMIN = 'admin';

    public const string MODERATOR = 'moderator';

    public const string MEMBER = 'member';

    public const array ROLES = [self::OWNER, self::ADMIN, self::MODERATOR, self::MEMBER];

    public const array MANAGERS = [self::OWNER, self::ADMIN];

    public const array MODERATORS = [self::OWNER, self::ADMIN, self::MODERATOR];

    public const string NOTIFY_ALL = 'all';

    public const string NOTIFY_MENTIONS = 'mentions';

    public const string NOTIFY_MUTE = 'mute';

    public const array NOTIFY = [self::NOTIFY_ALL, self::NOTIFY_MENTIONS, self::NOTIFY_MUTE];

    public $timestamps = false;

    protected $fillable = ['chat_id', 'person_id', 'role', 'notify', 'last_read_message_id', 'last_delivered_message_id', 'draft', 'joined_at'];

    protected $attributes = ['role' => self::MEMBER, 'notify' => self::NOTIFY_ALL];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
