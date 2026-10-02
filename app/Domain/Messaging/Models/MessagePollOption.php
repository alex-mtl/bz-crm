<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An answer option of a poll held in a chat (ФО §6.6.2).
 *
 * @property int $id
 * @property int $message_id
 * @property string $text
 * @property int $sort_order
 */
class MessagePollOption extends Model
{
    protected $table = 'message_poll_options';

    public $timestamps = false;

    protected $fillable = ['message_id', 'text', 'sort_order'];
}
