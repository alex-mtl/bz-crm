<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A message (ТЗ §20). Any message can be the root of a thread: replies point at their parent (parent_id), and
 * every message of a thread knows its root. A forwarded message carries no text of its own — it points at the
 * original, which each reader sees only if they may read the chat it came from.
 *
 * @property int $id
 * @property int $chat_id
 * @property int $author_person_id
 * @property string|null $body
 * @property int|null $parent_id
 * @property int|null $root_id
 * @property int $depth
 * @property int|null $quoted_message_id
 * @property int|null $forwarded_from_message_id
 * @property string $kind
 * @property string $status
 * @property Carbon|null $send_at
 * @property bool $mentions_all
 * @property Carbon|null $pinned_at
 * @property int|null $pinned_by_person_id
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 * @property int|null $deleted_by_user_id
 * @property int|null $task_id
 * @property Carbon $created_at
 * @property-read Person $author
 * @property-read Chat $chat
 * @property-read Message|null $quoted
 * @property-read Message|null $forwardedFrom
 */
class Message extends Model
{
    public const string TEXT = 'text';

    public const string POLL = 'poll';

    public const string SYSTEM = 'system';

    public const string SENT = 'sent';

    public const string SCHEDULED = 'scheduled';

    protected $fillable = [
        'chat_id', 'author_person_id', 'body', 'parent_id', 'root_id', 'depth', 'quoted_message_id', 'forwarded_from_message_id',
        'kind', 'status', 'send_at', 'mentions_all', 'pinned_at', 'pinned_by_person_id', 'edited_at', 'deleted_at', 'deleted_by_user_id', 'task_id',
    ];

    protected $attributes = ['kind' => self::TEXT, 'status' => self::SENT, 'depth' => 0, 'mentions_all' => false];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime', 'send_at' => 'datetime', 'pinned_at' => 'datetime', 'deleted_at' => 'datetime', 'mentions_all' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_person_id');
    }

    /**
     * @return BelongsTo<Chat, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function quoted(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quoted_message_id');
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function forwardedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forwarded_from_message_id');
    }

    /**
     * @return HasMany<MessageAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class)->orderBy('id');
    }

    /**
     * @return HasMany<MessageReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * @return HasMany<MessagePollOption, $this>
     */
    public function pollOptions(): HasMany
    {
        return $this->hasMany(MessagePollOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsToMany<Person, $this>
     */
    public function mentioned(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'message_mentions');
    }

    public function isSent(): bool
    {
        return $this->status === self::SENT;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isForward(): bool
    {
        return $this->forwarded_from_message_id !== null;
    }
}
