<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person and an event (ФО §6.7): invited or came by themselves, what they answered, whether they were there.
 * The person need not have an account — a supporter marked as present is an attendee too.
 *
 * @property int $id
 * @property int $event_id
 * @property int $person_id
 * @property int|null $invited_by_person_id
 * @property Carbon|null $invited_at
 * @property string|null $rsvp
 * @property string|null $rsvp_comment
 * @property Carbon|null $rsvp_at
 * @property bool|null $attended
 * @property int|null $attendance_marked_by_user_id
 * @property Carbon|null $attendance_marked_at
 * @property-read Person $person
 * @property-read Event $event
 */
class EventAttendee extends Model
{
    public const string GOING = 'going';

    public const string INTERESTED = 'interested';

    public const string DECLINED = 'declined';

    public const array ANSWERS = [self::GOING, self::INTERESTED, self::DECLINED];

    protected $fillable = [
        'event_id', 'person_id', 'invited_by_person_id', 'invited_at', 'rsvp', 'rsvp_comment', 'rsvp_at',
        'attended', 'attendance_marked_by_user_id', 'attendance_marked_at',
    ];

    protected function casts(): array
    {
        return ['invited_at' => 'datetime', 'rsvp_at' => 'datetime', 'attendance_marked_at' => 'datetime', 'attended' => 'boolean'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
