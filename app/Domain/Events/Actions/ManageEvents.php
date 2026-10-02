<?php

declare(strict_types=1);

namespace App\Domain\Events\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Exceptions\EventRuleViolation;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttachment;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Events\Models\EventReminder;
use App\Domain\Events\Notifications\EventNotice;
use App\Domain\Files\FileGate;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Events (ФО §6.7): creating — once or as a recurring series, changing, cancelling, publishing the results.
 * The organizer addresses only the audiences their rights reach, exactly as an author of a post does.
 */
final readonly class ManageEvents
{
    public const int MAX_OCCURRENCES = 60;

    public const array FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public function __construct(
        private AuthorizationService $authorization,
        private EventVisibility $visibility,
        private GroupAccess $groups,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $data  title, description, type_code, starts_at, ends_at, location, latitude, longitude,
     *                                      visibility, territory_ids, group_ids, reminder_minutes,
     *                                      recurrence: array{frequency: string, until: Carbon|string}
     * @return Event the event, or the first occurrence of the series
     */
    public function create(User $actor, array $data): Event
    {
        $attributes = $this->validated($data, null);
        $audience = $this->audience($actor, $data);
        $starts = $this->occurrences($attributes['starts_at'], $data['recurrence'] ?? null);
        $duration = $attributes['starts_at']->diffInSeconds($attributes['ends_at']);
        $seriesId = count($starts) > 1 ? (string) Str::uuid() : null;

        return DB::transaction(function () use ($actor, $attributes, $audience, $starts, $duration, $seriesId): Event {
            $first = null;
            foreach ($starts as $start) {
                $event = Event::query()->create([
                    ...$attributes,
                    'starts_at' => $start,
                    'ends_at' => $start->copy()->addSeconds((int) $duration),
                    'series_id' => $seriesId,
                    'visibility' => $audience['visibility'],
                    'organizer_person_id' => $actor->person_id,
                ]);
                $event->territories()->sync($audience['territory_ids']);
                $event->groups()->sync($audience['group_ids']);
                $this->syncReminders($event);
                $first ??= $event;
            }
            assert($first instanceof Event);
            $this->journal->record('events.event.created', $first, [], [
                'title' => $first->title, 'visibility' => $first->visibility, 'starts_at' => $first->starts_at->toIso8601String(),
                'occurrences' => count($starts), ...array_filter(['territory_ids' => $audience['territory_ids'], 'group_ids' => $audience['group_ids']]),
            ]);

            return $first;
        });
    }

    /**
     * Changes the event — or, with $following, this and every later occurrence of its series (time of day and
     * details; each occurrence keeps its own date). People going are told when the time or the place changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Event $event, array $data, bool $following = false): Event
    {
        $this->visibility->authorizeManaging($actor, 'events.update', $event);
        if ($event->isCancelled()) {
            throw EventRuleViolation::because('cancelled');
        }
        $attributes = $this->validated($data, $event);
        $audience = array_key_exists('visibility', $data) ? $this->audience($actor, $data) : null;
        $shift = $event->starts_at->diffInSeconds($attributes['starts_at'], false);
        $duration = $attributes['starts_at']->diffInSeconds($attributes['ends_at']);

        $changed = DB::transaction(function () use ($event, $attributes, $audience, $following, $shift, $duration): array {
            $changed = [];
            foreach ($this->targets($event, $following) as $target) {
                $start = $target->is($event) ? $attributes['starts_at'] : $target->starts_at->copy()->addSeconds((int) $shift);
                $before = $target->only(['starts_at', 'location']);
                $target->fill([...$attributes, 'starts_at' => $start, 'ends_at' => $start->copy()->addSeconds((int) $duration)]);
                if ($audience !== null) {
                    $target->visibility = $audience['visibility'];
                }
                $dirty = array_keys($target->getDirty());
                $target->save();
                if ($audience !== null) {
                    $target->territories()->sync($audience['territory_ids']);
                    $target->groups()->sync($audience['group_ids']);
                }
                $this->syncReminders($target);
                if ($dirty !== []) {
                    $this->journal->record('events.event.updated', $target, [], ['fields' => $dirty]);
                }
                if (! $before['starts_at']->equalTo($target->starts_at) || $before['location'] !== $target->location) {
                    $changed[] = $target;
                }
            }

            return $changed;
        });

        foreach ($changed as $target) {
            if (! $target->hasStarted()) {
                $this->tell($target, EventNotice::CHANGED);
            }
        }

        return $event->refresh();
    }

    public function cancel(User $actor, Event $event, string $reason, bool $following = false): void
    {
        $this->visibility->authorizeManaging($actor, 'events.update', $event);
        $reason = trim($reason);
        if ($reason === '') {
            throw EventRuleViolation::because('reason_required');
        }
        if ($event->isCancelled()) {
            return;
        }

        $cancelled = DB::transaction(function () use ($event, $reason, $following): array {
            $cancelled = [];
            foreach ($this->targets($event, $following) as $target) {
                if ($target->isCancelled()) {
                    continue;
                }
                $target->update(['cancelled_at' => now(), 'cancel_reason' => $reason]);
                EventReminder::query()->where('event_id', $target->id)->whereNull('sent_at')->delete();
                $this->journal->record('events.event.cancelled', $target, [], ['reason' => $reason]);
                $cancelled[] = $target;
            }

            return $cancelled;
        });

        foreach ($cancelled as $target) {
            if (! $target->isOver()) {
                $this->tell($target, EventNotice::CANCELLED, $reason);
            }
        }
    }

    /**
     * The results of an event that has begun: a text, documents, a photo report (ФО §6.7).
     *
     * @param  list<array{source: string, name: string, mime?: string|null}>  $files
     * @param  list<array{source: string, name: string, mime?: string|null}>  $photos
     */
    public function publishResults(User $actor, Event $event, ?string $results, array $files = [], array $photos = []): Event
    {
        $this->visibility->authorizeManaging($actor, 'events.results.publish', $event);
        if ($event->isCancelled()) {
            throw EventRuleViolation::because('cancelled');
        }
        if (! $event->hasStarted()) {
            throw EventRuleViolation::because('not_started');
        }
        $results = filled($results) ? trim((string) $results) : null;
        if ($results === null && $files === [] && $photos === [] && $event->results === null) {
            throw EventRuleViolation::because('results_empty');
        }
        $first = $event->results_published_at === null;

        DB::transaction(function () use ($actor, $event, $results, $files, $photos): void {
            $event->update(['results' => $results ?? $event->results, 'results_published_at' => now(), 'results_by_user_id' => $actor->id]);
            foreach (['file' => $files, 'photo' => $photos] as $kind => $list) {
                foreach ($list as $file) {
                    $this->attach($actor, $event, $file, $kind);
                }
            }
            $this->journal->record('events.results.published', $event, [], ['files' => count($files), 'photos' => count($photos)]);
        });

        if ($first) {
            $this->tell($event, EventNotice::RESULTS, null, attendedOnly: true);
        }

        return $event;
    }

    /**
     * The file of an attachment — for those who see the event (ТЗ §68).
     */
    public function attachmentPath(User $viewer, EventAttachment $attachment): string
    {
        $event = Event::query()->findOrFail($attachment->event_id);
        if (! $this->visibility->canSee($viewer, $event) || ! Storage::disk('local')->exists($attachment->path)) {
            throw new AuthorizationException(__('access.denied'));
        }

        return Storage::disk('local')->path($attachment->path);
    }

    /**
     * Reminders follow the event: unsent ones are rebuilt from reminder_minutes, sent ones stay as history.
     */
    public function syncReminders(Event $event): void
    {
        EventReminder::query()->where('event_id', $event->id)->whereNull('sent_at')->delete();
        foreach (array_unique($event->reminder_minutes ?? []) as $minutes) {
            $remindAt = $event->starts_at->copy()->subMinutes((int) $minutes);
            if ($remindAt->isFuture() && ! EventReminder::query()->where('event_id', $event->id)->where('minutes_before', $minutes)->exists()) {
                EventReminder::query()->create(['event_id' => $event->id, 'minutes_before' => (int) $minutes, 'remind_at' => $remindAt]);
            }
        }
    }

    /**
     * Sends the reminders whose time has come (scheduler). The update of sent_at is the claim: a reminder is
     * sent by whoever sets it first, so a repeated or a parallel run sends nothing twice.
     *
     * @return int reminders sent
     */
    public function sendDueReminders(): int
    {
        $sent = 0;
        $due = EventReminder::query()->whereNull('sent_at')->where('remind_at', '<=', now())->orderBy('remind_at')->get();
        foreach ($due as $reminder) {
            if (EventReminder::query()->whereKey($reminder->id)->whereNull('sent_at')->update(['sent_at' => now()]) !== 1) {
                continue;
            }
            $event = Event::query()->find($reminder->event_id);
            // Too late to remind: the event is cancelled or has already begun.
            if ($event === null || $event->isCancelled() || $event->hasStarted()) {
                continue;
            }
            $recipients = $this->recipients($event, [EventAttendee::GOING, EventAttendee::INTERESTED]);
            foreach ($recipients as $user) {
                $user->notify(new EventNotice(EventNotice::REMINDER, $event));
            }
            $reminder->update(['recipients' => $recipients->count()]);
            $this->journal->record('events.reminder.sent', $event, [], ['minutes_before' => $reminder->minutes_before, 'recipients' => $recipients->count()]);
            $sent++;
        }

        return $sent;
    }

    /**
     * Active accounts among the attendees: with one of the given answers; without $answers — everyone who has
     * not declined; with $attendedOnly — those who were there or said they would be.
     *
     * @param  list<string>|null  $answers
     * @return Collection<int, User>
     */
    public function recipients(Event $event, ?array $answers = null, bool $attendedOnly = false): Collection
    {
        $people = EventAttendee::query()->where('event_id', $event->id)
            ->when($answers !== null, fn ($query) => $query->whereIn('rsvp', $answers))
            ->when($answers === null && ! $attendedOnly, fn ($query) => $query->where(fn ($w) => $w->whereNull('rsvp')->orWhere('rsvp', '!=', EventAttendee::DECLINED)))
            ->when($attendedOnly, fn ($query) => $query->where(fn ($w) => $w->where('attended', true)->orWhere('rsvp', EventAttendee::GOING)))
            ->select('person_id');

        return User::query()->where('status', UserStatus::Active)->whereIn('person_id', $people)->get();
    }

    private function tell(Event $event, string $kind, ?string $note = null, bool $attendedOnly = false): void
    {
        foreach ($this->recipients($event, null, $attendedOnly) as $user) {
            if ($user->person_id !== $event->organizer_person_id) {
                $user->notify(new EventNotice($kind, $event, $note));
            }
        }
    }

    /**
     * @return list<Event>
     */
    private function targets(Event $event, bool $following): array
    {
        if (! $following || $event->series_id === null) {
            return [$event];
        }

        return Event::query()->where('series_id', $event->series_id)->where('starts_at', '>=', $event->starts_at)->orderBy('starts_at')->get()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, description: string|null, type_code: string, starts_at: Carbon, ends_at: Carbon,
     *               location: string|null, latitude: float|null, longitude: float|null, reminder_minutes: list<int>}
     */
    private function validated(array $data, ?Event $event): array
    {
        $title = trim((string) ($data['title'] ?? ($event !== null ? $event->title : '')));
        if ($title === '') {
            throw EventRuleViolation::because('title_required');
        }
        $type = (string) ($data['type_code'] ?? ($event !== null ? $event->type_code : ''));
        if (($event === null || $type !== $event->type_code) && ! CatalogItem::query()->ofCatalog('event_types')->selectable()->where('code', $type)->exists()) {
            throw EventRuleViolation::because('invalid_type');
        }
        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $event?->starts_at;
        if ($startsAt === null) {
            throw EventRuleViolation::because('time_required');
        }
        // Without an explicit end, a moved event keeps its length; a new one lasts an hour.
        $endsAt = match (true) {
            isset($data['ends_at']) => Carbon::parse($data['ends_at']),
            $event !== null => $startsAt->copy()->addSeconds((int) $event->starts_at->diffInSeconds($event->ends_at)),
            default => $startsAt->copy()->addHour(),
        };
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw EventRuleViolation::because('ends_before_start');
        }
        $latitude = filled($data['latitude'] ?? null) ? (float) $data['latitude'] : ($event !== null && ! array_key_exists('latitude', $data) ? $event->latitude : null);
        $longitude = filled($data['longitude'] ?? null) ? (float) $data['longitude'] : ($event !== null && ! array_key_exists('longitude', $data) ? $event->longitude : null);
        if (($latitude === null) !== ($longitude === null) || ($latitude !== null && (abs($latitude) > 90 || abs((float) $longitude) > 180))) {
            throw EventRuleViolation::because('invalid_point');
        }
        $reminders = array_key_exists('reminder_minutes', $data)
            ? array_values(array_unique(array_filter(array_map('intval', (array) $data['reminder_minutes']), fn (int $m): bool => $m > 0)))
            : ($event !== null ? ($event->reminder_minutes ?? []) : (array) config('events.reminder_minutes', [1440, 60]));

        return [
            'title' => mb_substr($title, 0, 255),
            'description' => array_key_exists('description', $data) ? (filled($data['description']) ? trim((string) $data['description']) : null) : $event?->description,
            'type_code' => $type,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'location' => array_key_exists('location', $data) ? (filled($data['location']) ? mb_substr(trim((string) $data['location']), 0, 255) : null) : $event?->location,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'reminder_minutes' => array_values($reminders),
        ];
    }

    /**
     * Checks that the organizer may address this audience and returns it normalized.
     *
     * @param  array<string, mixed>  $data
     * @return array{visibility: string, territory_ids: list<int>, group_ids: list<int>}
     */
    private function audience(User $actor, array $data): array
    {
        $visibility = (string) ($data['visibility'] ?? Event::PRIVATE);
        $ints = fn (string $key): array => array_values(array_unique(array_map('intval', array_filter((array) ($data[$key] ?? [])))));
        $audience = ['visibility' => $visibility, 'territory_ids' => [], 'group_ids' => []];

        switch ($visibility) {
            case Event::PRIVATE:
                $this->authorization->authorize($actor, 'events.create');
                break;
            case Event::PUBLIC:
                if (! $this->visibility->mayAddressEveryone($actor)) {
                    throw new AuthorizationException(__('access.denied'));
                }
                break;
            case Event::REGIONAL:
                $audience['territory_ids'] = $ints('territory_ids');
                $territories = Territory::query()->whereKey($audience['territory_ids'])->get();
                if ($territories->isEmpty() || $territories->count() !== count($audience['territory_ids'])) {
                    throw EventRuleViolation::because('audience_required');
                }
                foreach ($territories as $territory) {
                    if (! $this->visibility->coversTerritory($actor, $territory)) {
                        throw new AuthorizationException(__('events.errors.territory_out_of_reach', ['name' => $territory->name()]));
                    }
                }
                break;
            case Event::GROUP:
                $audience['group_ids'] = $ints('group_ids');
                $groups = Group::query()->whereKey($audience['group_ids'])->whereNull('archived_at')->get();
                if ($groups->isEmpty() || $groups->count() !== count($audience['group_ids'])) {
                    throw EventRuleViolation::because('audience_required');
                }
                $mayCreate = $this->authorization->can($actor, 'events.create');
                foreach ($groups as $group) {
                    // A member with the right to create events, or the one who runs the group ("Св — админ группы").
                    $allowed = ($mayCreate && $this->groups->isMember($group, $actor->person_id)) || $this->manages($actor, $group);
                    if (! $allowed) {
                        throw new AuthorizationException(__('access.denied'));
                    }
                }
                break;
            default:
                throw EventRuleViolation::because('invalid_visibility');
        }

        return $audience;
    }

    private function manages(User $actor, Group $group): bool
    {
        return in_array($this->groups->roleOf($group, $actor->person_id), ['owner', 'admin'], true);
    }

    /**
     * @param  array<string, mixed>|null  $recurrence
     * @return list<Carbon> the start of every occurrence
     */
    private function occurrences(Carbon $start, ?array $recurrence): array
    {
        if ($recurrence === null || blank($recurrence['frequency'] ?? null)) {
            return [$start];
        }
        $frequency = (string) $recurrence['frequency'];
        $until = isset($recurrence['until']) ? Carbon::parse($recurrence['until'])->endOfDay() : null;
        if (! in_array($frequency, self::FREQUENCIES, true) || $until === null || $until->lessThan($start)) {
            throw EventRuleViolation::because('invalid_recurrence');
        }

        $starts = [];
        for ($i = 0; ; $i++) {
            $next = match ($frequency) {
                'daily' => $start->copy()->addDays($i),
                'weekly' => $start->copy()->addWeeks($i),
                default => $start->copy()->addMonthsNoOverflow($i),
            };
            if ($next->greaterThan($until)) {
                break;
            }
            if (count($starts) === self::MAX_OCCURRENCES) {
                throw EventRuleViolation::because('too_many_occurrences', ['limit' => self::MAX_OCCURRENCES]);
            }
            $starts[] = $next;
        }

        return $starts;
    }

    /**
     * @param  array{source: string, name: string, mime?: string|null}  $file
     */
    private function attach(User $actor, Event $event, array $file, string $kind): void
    {
        // ФО §6.6.4, ADR-012: an infected file is refused before it reaches the storage.
        app(FileGate::class)->ensureAcceptable($file['source'], $file['name']);
        $extension = Str::lower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $path = 'events/'.$event->id.'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
        Storage::disk('local')->put($path, (string) file_get_contents($file['source']));

        EventAttachment::query()->create([
            'event_id' => $event->id,
            'kind' => $kind,
            'path' => $path,
            'original_name' => mb_substr($file['name'], 0, 255),
            'mime' => $file['mime'] ?? (Storage::disk('local')->mimeType($path) ?: null),
            'size' => Storage::disk('local')->size($path),
            'uploaded_by_user_id' => $actor->id,
        ]);
    }
}
