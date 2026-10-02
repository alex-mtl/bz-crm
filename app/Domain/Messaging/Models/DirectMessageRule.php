<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Д-26: may a holder of the sender role start a direct dialog with a holder of the recipient role? A missing rule means "may".
 *
 * @property int $id
 * @property int $sender_role_id
 * @property int $recipient_role_id
 * @property bool $allowed
 */
class DirectMessageRule extends Model
{
    protected $table = 'direct_message_rules';

    protected $fillable = ['sender_role_id', 'recipient_role_id', 'allowed'];

    protected function casts(): array
    {
        return ['allowed' => 'boolean'];
    }
}
