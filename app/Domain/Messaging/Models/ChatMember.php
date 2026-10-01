<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property int $person_id
 * @property Carbon $joined_at
 */
class ChatMember extends Model
{
    public $timestamps = false;

    protected $fillable = ['chat_id', 'person_id', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }
}
