<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A vote in a poll of a chat: one per person, changeable.
 *
 * @property int $id
 * @property int $message_id
 * @property int $option_id
 * @property int $person_id
 */
class MessagePollVote extends Model
{
    protected $table = 'message_poll_votes';

    protected $fillable = ['message_id', 'option_id', 'person_id'];
}
