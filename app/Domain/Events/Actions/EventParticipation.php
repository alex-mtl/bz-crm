<?php

declare(strict_types=1);

namespace App\Domain\Events\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Events\Events\AttendanceMarked;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Exceptions\EventRuleViolation;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Events\Notifications\EventNotice;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Retraction;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * People and an event (ФО §6.7): invitations — personal and in bulk, the answer of each person, the mark of
 * who was really there. An invitation opens the event to the invited whatever its visibility; withdrawing it
 * closes the event again and empties the notifications already sent about it.
 */
final readonly class EventParticipation
{
    public const int BULK_LIMIT = 2000;

    public function __construct(
        private AuthorizationService $authorization,
        private EventVisibility $visibility,
        private Retraction $retraction,
        private EventJournal $journal,
    ) {}

    /**
     * Invites people one by one. The inviter may invite only the people they can see themselves.
     *
     * @param  list<int>  $personIds
     * @return int new invitations
     */
    public function invite(User $actor, Event $event, array $personIds): int
    {
        $this->ensureOpen($event);
        $this->visibility->authorizeManaging($actor, 'events.invite', $event);
        $people = Person::query()->whereKey(array_values(array_unique(array_map('intval', $personIds))))->get();
        foreach ($people as $person) {
            $this->authorization->authorize($actor, 'people.read', $person);
        }

        return $this->issue($actor, $event, $people->all(), bulk: false);
    }

    /**
     * Invites everyone from a selection the bulk right reaches (ФО §6.7 "группам, регионам, спискам").
     * The selection is a query over people built by the caller; the scope of the right is applied here.
     *
     * @param  Builder<Person>  $people
     * @return int new invitations
     */
    public function inviteBulk(User $actor, Event $event, Builder $people): int
    {
        $this->ensureOpen($event);
        $this->visibility->authorizeManaging($actor, 'events.invite', $event);
        $this->authorization->authorize($actor, 'events.invite.bulk');

        $candidates = $this->authorization->scopeQuery($actor, 'events.invite.bulk', $people)
            ->whereHas('user', fn (Builder $user) => $user->where('status', UserStatus::Active))
            ->whereNotIn('people.id', EventAttendee::query()->where('event_id', $event->id)->whereNotNull('invited_at')->select('person_id'))
            ->limit(self::BULK_LIMIT + 1)->get();
        if ($candidates->count() > self::BULK_LIMIT) {
            throw EventRuleViolation::because('bulk_too_large', ['limit' => self::BULK_LIMIT]);
        }

        return $this->issue($actor, $event, $candidates->all(), bulk: true);
    }

    /**
     * Withdraws an invitation. If the event is no longer visible to the person, what they were told about it is emptied.
     */
    public function uninvite(User $actor, Event $event, Person $person): void
    {
        $this->visibility->authorizeManaging($actor, 'events.invite', $event);
        $attendee = EventAttendee::query()->where('event_id', $event->id)->where('person_id', $person->id)->first();
        if ($attendee === null) {
            return;
        }
        if ($attendee->attended === true) {
            throw EventRuleViolation::because('already_attended');
        }

        DB::transaction(function () use ($event, $attendee, $person): void {
            $attendee->delete();
            $this->journal->record('events.invitation.withdrawn', $event, [], ['person_id' => $person->id]);
        });

        $user = $person->user;
        if ($user !== null && ! $this->visibility->canSee($user, $event)) {
            $this->retraction->retract('event', $event->id, [$user->id]);
        }
    }

    /**
     * "Иду / интересует / не иду" with an optional comment — for anyone who sees the event, invited or not.
     */
    public function respond(User $actor, Event $event, string $answer, ?string $comment = null): EventAttendee
    {
        $this->authorization->authorize($actor, 'events.rsvp');
        if (! $this->visibility->canSee($actor, $event)) {
            throw new AuthorizationException(__('access.denied'));
        }
        $this->ensureOpen($event);
        if ($event->isOver()) {
            throw EventRuleViolation::because('already_over');
        }
        if (! in_array($answer, EventAttendee::ANSWERS, true)) {
            throw EventRuleViolation::because('invalid_answer');
        }

        return DB::transaction(function () use ($actor, $event, $answer, $comment): EventAttendee {
            $attendee = EventAttendee::query()->firstOrNew(['event_id' => $event->id, 'person_id' => $actor->person_id]);
            $old = $attendee->rsvp;
            $attendee->fill([
                'rsvp' => $answer, 'rsvp_at' => now(),
                'rsvp_comment' => filled($comment) ? mb_substr(trim((string) $comment), 0, 500) : null,
            ])->save();
            if ($old !== $answer) {
                $this->journal->record('events.rsvp.set', $event, ['rsvp' => $old], ['rsvp' => $answer]);
            }

            return $attendee;
        });
    }

    /**
     * Marks who was really there — also people who were not invited, and people without an account (ФО §6.7
     * "список участников"). The fact goes out as a domain event for the CRM feed and the reports.
     */
    public function markAttendance(User $actor, Event $event, Person $person, bool $attended): EventAttendee
    {
        $this->visibility->authorizeManaging($actor, 'events.attendance.mark', $event);
        $this->ensureOpen($event);
        if (! $event->hasStarted()) {
            throw EventRuleViolation::because('not_started');
        }
        $attendee = EventAttendee::query()->firstOrNew(['event_id' => $event->id, 'person_id' => $person->id]);
        if (! $attendee->exists) {
            // Someone who just came: the one who marks must at least be allowed to see this person.
            $this->authorization->authorize($actor, 'people.read', $person);
        }
        if ($attendee->exists && $attendee->attended === $attended) {
            return $attendee;
        }

        DB::transaction(function () use ($actor, $event, $person, $attendee, $attended): void {
            $attendee->fill(['attended' => $attended, 'attendance_marked_by_user_id' => $actor->id, 'attendance_marked_at' => now()])->save();
            $this->journal->record('events.attendance.marked', $event, [], ['person_id' => $person->id, 'attended' => $attended]);
            event(new AttendanceMarked($event, $person->id, $attended, $actor->id));
        });

        return $attendee;
    }

    /**
     * @param  list<Person>  $people
     */
    private function issue(User $actor, Event $event, array $people, bool $bulk): int
    {
        $invited = [];
        DB::transaction(function () use ($actor, $event, $people, $bulk, &$invited): void {
            foreach ($people as $person) {
                $attendee = EventAttendee::query()->firstOrNew(['event_id' => $event->id, 'person_id' => $person->id]);
                if ($attendee->invited_at !== null) {
                    continue;
                }
                $attendee->fill(['invited_by_person_id' => $actor->person_id, 'invited_at' => now()])->save();
                $invited[] = $person;
                if (! $bulk) {
                    $this->journal->record('events.invitation.sent', $event, [], ['person_id' => $person->id]);
                }
            }
            if ($bulk && $invited !== []) {
                $this->journal->record('events.invitation.bulk_sent', $event, [], ['count' => count($invited)]);
            }
        });

        foreach ($invited as $person) {
            $user = $person->user;
            if ($user !== null && $user->isActive() && $user->person_id !== $actor->person_id) {
                $user->notify(new EventNotice(EventNotice::INVITED, $event));
            }
        }

        return count($invited);
    }

    private function ensureOpen(Event $event): void
    {
        if ($event->isCancelled()) {
            throw EventRuleViolation::because('cancelled');
        }
    }
}
