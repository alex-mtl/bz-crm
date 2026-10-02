<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reaction of a person to a message (ФО §6.6.4): one per person and message, from the catalog of reactions.
 *
 * @property int $id
 * @property int $message_id
 * @property int $person_id
 * @property string $reaction_code
 */
class MessageReaction extends Model
{
    protected $table = 'message_reactions';

    protected $fillable = ['message_id', 'person_id', 'reaction_code'];
}
