<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reminder of an event (ФО §6.7): due at remind_at, sent once — sent_at is the claim.
 *
 * @property int $id
 * @property int $event_id
 * @property int $minutes_before
 * @property Carbon $remind_at
 * @property Carbon|null $sent_at
 * @property int $recipients
 */
class EventReminder extends Model
{
    protected $table = 'event_reminders';

    protected $fillable = ['event_id', 'minutes_before', 'remind_at', 'sent_at', 'recipients'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
