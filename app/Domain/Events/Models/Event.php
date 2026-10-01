<?php

declare(strict_types=1);

namespace App\Domain\Events\Models;

use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\Models\Group;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An event (ФО §6.7, ТЗ §22). Its audience is described like that of a post — a visibility level plus the rows of
 * event_territories / event_groups; whoever is invited sees it whatever the level. An occurrence of a recurring
 * event is an ordinary event sharing series_id with the others.
 *
 * @property int $id
 * @property string|null $series_id
 * @property string $title
 * @property string|null $description
 * @property string $type_code
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $location
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string $visibility
 * @property int $organizer_person_id
 * @property list<int>|null $reminder_minutes
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $results
 * @property Carbon|null $results_published_at
 * @property int|null $results_by_user_id
 * @property Carbon $created_at
 * @property-read Person $organizer
 */
class Event extends Model
{
    public const string PUBLIC = 'public';

    public const string REGIONAL = 'regional';

    public const string GROUP = 'group';

    public const string PRIVATE = 'private';

    public const array VISIBILITIES = [self::PUBLIC, self::REGIONAL, self::GROUP, self::PRIVATE];

    protected $fillable = [
        'series_id', 'title', 'description', 'type_code', 'starts_at', 'ends_at', 'location', 'latitude', 'longitude',
        'visibility', 'organizer_person_id', 'reminder_minutes', 'cancelled_at', 'cancel_reason',
        'results', 'results_published_at', 'results_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime', 'results_published_at' => 'datetime',
            'reminder_minutes' => 'array', 'latitude' => 'float', 'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'organizer_person_id');
    }

    /**
     * @return BelongsToMany<Territory, $this>
     */
    public function territories(): BelongsToMany
    {
        return $this->belongsToMany(Territory::class, 'event_territories');
    }

    /**
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'event_groups');
    }

    /**
     * @return HasMany<EventAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(EventAttendee::class);
    }

    /**
     * @return HasMany<EventReminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(EventReminder::class)->orderBy('remind_at');
    }

    /**
     * @return HasMany<EventAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(EventAttachment::class)->orderBy('id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }

    public function isOver(): bool
    {
        return $this->ends_at->isPast();
    }

    public function isRecurring(): bool
    {
        return $this->series_id !== null;
    }
}
