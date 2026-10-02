<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chat (ФО §6.6, ТЗ §20–21): a direct dialog of two people, a group chat with its own members and roles, or the
 * discussion of an object — a task, a project, a group — whose access follows that object.
 *
 * @property int $id
 * @property string $type
 * @property string|null $title
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $created_by_person_id
 * @property string|null $direct_key
 * @property Carbon|null $last_message_at
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 */
class Chat extends Model
{
    public const string DIRECT = 'direct';

    public const string GROUP = 'group';

    public const string SUBJECT = 'subject';

    protected $fillable = ['type', 'title', 'subject_type', 'subject_id', 'created_by_person_id', 'direct_key', 'last_message_at', 'archived_at'];

    protected $attributes = ['type' => self::SUBJECT];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    /**
     * @return HasMany<ChatMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ChatMember::class);
    }

    public static function directKey(int $personA, int $personB): string
    {
        return min($personA, $personB).':'.max($personA, $personB);
    }

    public function isDirect(): bool
    {
        return $this->type === self::DIRECT;
    }

    public function isGroup(): bool
    {
        return $this->type === self::GROUP;
    }

    public function isSubject(): bool
    {
        return $this->type === self::SUBJECT;
    }
}
