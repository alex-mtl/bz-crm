<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discussion attached to an object — in phase 2, one thread per task (ФО §6.8.2).
 * Phase 6 builds group chats, threads and reactions on the same tables.
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 */
class Chat extends Model
{
    protected $fillable = ['subject_type', 'subject_id'];

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
}
