<?php

use App\Domain\CRM\Models\Interaction;
use App\Domain\Events\Actions\EventParticipation;
use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\CalendarExport;
use App\Domain\Events\Events\AttendanceMarked;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Exceptions\EventRuleViolation;
use App\Domain\Events\Models\CalendarFeed;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Events\Models\EventReminder;
use App\Domain\Events\Notifications\EventNotice;
use App\Domain\Groups\Models\Group;
use App\Domain\Identity\Actions\SetUserActive;
use App\Domain\Identity\Models\User;
use App\Domain\People\Actions\ManagePeople;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SocialFixture;

/*
 * ФО §6.7, ТЗ §22 — events: audience and visibility as for posts, invitations, RSVP, attendance, reminders,
 * recurring events, results, calendar export.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->events = app(ManageEvents::class);
    $this->people = app(EventParticipation::class);
    $this->visibility = app(EventVisibility::class);
    $this->event = fn (User $organizer, array $data = []): Event => $this->events->create($organizer, [
        // Exactly 48 hours ahead, whatever the time of day the suite runs at: the tests below travel in time.
        'title' => 'Întâlnire', 'type_code' => 'meeting', 'starts_at' => now()->addDays(2), ...$data,
    ]);
    $this->sees = fn (User $user, Event $event): bool => $this->visibility->canSee($user, $event);
});

it('lets an organizer address only the audiences their rights reach', function () {
    $o = $this->org;
    $group = $this->social->group($o->a1, 'Voluntari');
    $regional = fn (User $user, int $territoryId) => fn () => ($this->event)($user, ['visibility' => Event::REGIONAL, 'territory_ids' => [$territoryId]]);

    $private = ($this->event)($o->a1, ['location' => ' Sediu ', 'latitude' => 47.02, 'longitude' => 28.83]);

    expect($private)->visibility->toBe(Event::PRIVATE)->location->toBe('Sediu')->organizer_person_id->toBe($o->a1->person_id)
        ->and((int) round($private->starts_at->diffInMinutes($private->ends_at)))->toBe(60)
        ->and(journalCount('events.event.created'))->toBe(1)
        // An employee holds private events and events of their own groups — not regional or public ones.
        ->and(($this->event)($o->a1, ['visibility' => Event::GROUP, 'group_ids' => [$group->id]])->visibility)->toBe(Event::GROUP)
        ->and(fn () => ($this->event)($o->a2, ['visibility' => Event::GROUP, 'group_ids' => [$group->id]]))->toThrow(AuthorizationException::class)
        ->and($regional($o->a1, $o->centru->id))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->event)($o->a1, ['visibility' => Event::PUBLIC]))->toThrow(AuthorizationException::class)
        // A head — inside their own territories; the head of the organization — anywhere and for everyone.
        ->and($regional($o->headA, $o->centru->id)()->visibility)->toBe(Event::REGIONAL)
        ->and($regional($o->headA, $o->botanica->id))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->event)($o->headA, ['visibility' => Event::PUBLIC]))->toThrow(AuthorizationException::class)
        ->and($regional($o->orgHead, $o->baltiTerritory->id)()->visibility)->toBe(Event::REGIONAL)
        ->and(($this->event)($o->orgHead, ['visibility' => Event::PUBLIC])->visibility)->toBe(Event::PUBLIC)
        ->and(fn () => ($this->event)($o->a1, ['title' => ' ']))->toThrow(EventRuleViolation::class)
        ->and(fn () => ($this->event)($o->a1, ['type_code' => 'party']))->toThrow(EventRuleViolation::class)
        ->and(fn () => ($this->event)($o->a1, ['ends_at' => now()->addDay()]))->toThrow(EventRuleViolation::class)
        ->and(fn () => ($this->event)($o->a1, ['latitude' => 47.0]))->toThrow(EventRuleViolation::class)
        ->and(fn () => ($this->event)($o->headA, ['visibility' => Event::REGIONAL]))->toThrow(EventRuleViolation::class);
});

it('shows an event by its visibility, and to the invited whatever the visibility', function () {
    $o = $this->org;
    $group = $this->social->group($o->a1, 'Voluntari', Group::CLOSED, [$o->b1]);
    $public = ($this->event)($o->orgHead, ['visibility' => Event::PUBLIC]);
    $regional = ($this->event)($o->headA, ['visibility' => Event::REGIONAL, 'territory_ids' => [$o->centru->id]]);
    $inGroup = ($this->event)($o->a1, ['visibility' => Event::GROUP, 'group_ids' => [$group->id]]);
    $private = ($this->event)($o->a1);
    $this->people->invite($o->a1, $private, [$o->balti1->person_id]);
    $candidate = userWithRoles('candidate');
    $this->people->invite($o->headA, $regional, [$candidate->person_id]);

    expect(($this->sees)($o->balti1, $public))->toBeTrue()
        ->and(($this->sees)($o->a2, $regional))->toBeTrue()
        ->and(($this->sees)($o->regionHead, $regional))->toBeTrue()
        ->and(($this->sees)($o->b1, $regional))->toBeFalse()
        ->and(($this->sees)($o->balti1, $regional))->toBeFalse()
        ->and(($this->sees)($o->b1, $inGroup))->toBeTrue()
        ->and(($this->sees)($o->a2, $inGroup))->toBeFalse()
        ->and(($this->sees)($o->balti1, $private))->toBeTrue()
        ->and(($this->sees)($o->a2, $private))->toBeFalse()
        // Д-24: those who answer for the organizer see the event whatever its level — the others do not.
        ->and(($this->sees)($o->headA, $private))->toBeTrue()
        ->and(($this->sees)($o->regionHead, $private))->toBeTrue()
        ->and(($this->sees)($o->admin, $private))->toBeTrue()
        ->and(($this->sees)($o->headB, $private))->toBeFalse()
        ->and(($this->sees)($o->baltiHead, $private))->toBeFalse()
        // A candidate sees public events (Д-25) and what they were invited to — nothing else.
        ->and(($this->sees)($candidate, $regional))->toBeTrue()
        ->and(($this->sees)($candidate, $public))->toBeTrue()
        ->and(($this->sees)($candidate, $inGroup))->toBeFalse()
        ->and(($this->sees)($candidate, $private))->toBeFalse()
        ->and($this->visibility->visibleTo($o->b1)->pluck('id')->all())->toEqualCanonicalizing([$public->id, $inGroup->id]);
});

it('invites people the inviter can see, and withdraws an invitation together with what was told about it', function () {
    $o = $this->org;
    $event = ($this->event)($o->a1, ['title' => 'Ședință închisă']);

    expect($this->people->invite($o->a1, $event, [$o->a2->person_id, $o->b1->person_id]))->toBe(2)
        ->and($this->people->invite($o->a1, $event, [$o->a2->person_id]))->toBe(0)
        ->and(journalCount('events.invitation.sent'))->toBe(2)
        ->and(fn () => $this->people->invite($o->a2, $event, [$o->balti1->person_id]))->toThrow(AuthorizationException::class)
        // Д-24: the head of the organizer's branch sees and runs the events of their people — a private one too.
        ->and($this->people->invite($o->headA, $event, [$o->regionHead->person_id]))->toBe(1)
        // The head of another branch does not see it; once invited, sees it and still does not run it.
        ->and(fn () => $this->people->invite($o->headB, $event, [$o->orgHead->person_id]))->toThrow(AuthorizationException::class)
        ->and($this->people->invite($o->a1, $event, [$o->headB->person_id]))->toBe(1)
        ->and(fn () => $this->people->invite($o->headB, $event, [$o->orgHead->person_id]))->toThrow(AuthorizationException::class);

    $notice = $o->b1->notifications()->sole();
    expect($notice->data['title'])->toContain('Ședință închisă')
        ->and($notice->getAttribute('category'))->toBe('events')
        ->and($notice->getAttribute('subject_type'))->toBe('event')
        ->and((int) $notice->getAttribute('subject_id'))->toBe($event->id);

    $this->people->uninvite($o->a1, $event, $o->b1->person);

    expect(($this->sees)($o->b1, $event))->toBeFalse()
        ->and($notice->fresh()->data['title'])->toBe(__('notifications.retracted'))
        ->and(json_encode($notice->fresh()->data))->not->toContain('Ședință închisă')
        ->and($notice->fresh()->getAttribute('retracted_at'))->not->toBeNull()
        ->and($o->a2->notifications()->sole()->data['title'])->toContain('Ședință închisă')
        ->and(journalCount('events.invitation.withdrawn'))->toBe(1);
});

it('invites in bulk only the people the bulk right reaches', function () {
    Notification::fake();
    $o = $this->org;
    $event = ($this->event)($o->headA, ['visibility' => Event::REGIONAL, 'territory_ids' => [$o->centru->id]]);
    $ofEmployee = ($this->event)($o->a1);

    expect($this->people->inviteBulk($o->headA, $event, Person::query()))->toBe(3)   // the head, a1 and a2 — the branch
        ->and($this->people->inviteBulk($o->headA, $event, Person::query()))->toBe(0)
        ->and(journalCount('events.invitation.bulk_sent'))->toBe(1)
        ->and(fn () => $this->people->inviteBulk($o->a1, $ofEmployee, Person::query()))->toThrow(AuthorizationException::class);
    Notification::assertSentTo($o->a2, EventNotice::class, fn (EventNotice $notice) => $notice->kind === EventNotice::INVITED);
    Notification::assertNotSentTo($o->b1, EventNotice::class);
    Notification::assertNotSentTo($o->headA, EventNotice::class);
});

it('takes an answer from anyone who sees the event', function () {
    $o = $this->org;
    $event = ($this->event)($o->headA, ['visibility' => Event::REGIONAL, 'territory_ids' => [$o->centru->id]]);

    $this->people->respond($o->a1, $event, EventAttendee::INTERESTED);
    $answer = $this->people->respond($o->a1, $event, EventAttendee::GOING, ' Vin cu mașina ');
    $this->people->respond($o->a1, $event, EventAttendee::GOING);
    $this->people->respond($o->a2, $event, EventAttendee::DECLINED, 'Sunt plecat');

    expect($answer)->rsvp->toBe(EventAttendee::GOING)->rsvp_comment->toBe('Vin cu mașina')
        ->and(EventAttendee::query()->where('event_id', $event->id)->count())->toBe(2)
        ->and(journalCount('events.rsvp.set'))->toBe(3)
        ->and(fn () => $this->people->respond($o->b1, $event, EventAttendee::GOING))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->people->respond($o->a1, $event, 'maybe'))->toThrow(EventRuleViolation::class);

    $this->travel(3)->days();

    expect(fn () => $this->people->respond($o->a2, $event, EventAttendee::GOING))->toThrow(EventRuleViolation::class);
});

it('marks attendance only after the event has begun, and announces the fact as a domain event', function () {
    $o = $this->org;
    $event = ($this->event)($o->a1, ['title' => 'Întâlnire cu susținătorii']);
    $this->people->invite($o->a1, $event, [$o->a2->person_id]);

    expect(fn () => $this->people->markAttendance($o->a1, $event, $o->a2->person, true))->toThrow(EventRuleViolation::class);

    $this->travel(2)->days();
    $this->travel(1)->hours();
    EventBus::fake([AttendanceMarked::class]);
    $this->people->markAttendance($o->a1, $event, $o->a2->person, true);
    EventBus::assertDispatched(AttendanceMarked::class, fn (AttendanceMarked $marked) => $marked->attended && $marked->personId === $o->a2->person_id);
});

it('marks people without an account too, and turns the mark into a fact in the feed of the person', function () {
    $o = $this->org;
    $event = ($this->event)($o->a1, ['title' => 'Întâlnire cu susținătorii', 'starts_at' => now()->addHour()]);
    $supporter = app(ManagePeople::class)->create($o->admin, [
        'first_name' => 'Doina', 'last_name' => 'Vizitator', 'person_type' => 'supporter',
        'territory_id' => $o->centru->id, 'responsible_unit_id' => $o->branchA->id,
    ]);
    $this->travel(2)->hours();

    $this->people->markAttendance($o->a1, $event, $supporter, true);
    $this->people->markAttendance($o->a1, $event, $supporter, true);
    $visit = Interaction::query()->where('person_id', $supporter->id)->where('kind_code', 'event_visit')->sole();

    expect($visit->summary)->toBe('Întâlnire cu susținătorii')
        ->and($visit->occurred_at->equalTo($event->starts_at))->toBeTrue()
        ->and(EventAttendee::query()->where('event_id', $event->id)->where('person_id', $supporter->id)->sole()->attended)->toBeTrue()
        ->and(journalCount('events.attendance.marked'))->toBe(1)
        // Another branch neither marks, nor adds a person it cannot see.
        ->and(fn () => $this->people->markAttendance($o->headB, $event, $o->b1->person, true))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->people->uninvite($o->a1, $event, $supporter))->toThrow(EventRuleViolation::class);

    $this->people->markAttendance($o->a1, $event, $supporter, false);

    expect(Interaction::query()->where('person_id', $supporter->id)->where('kind_code', 'event_visit')->exists())->toBeFalse();
});

it('reminds the people going — once, however many times the scheduler runs', function () {
    Notification::fake();
    $o = $this->org;
    $event = ($this->event)($o->headA, ['visibility' => Event::REGIONAL, 'territory_ids' => [$o->centru->id]]);
    $this->people->respond($o->a1, $event, EventAttendee::GOING);
    $this->people->respond($o->a2, $event, EventAttendee::INTERESTED);
    $this->people->respond($o->regionHead, $event, EventAttendee::DECLINED);
    $this->people->invite($o->headA, $event, [$o->orgHead->person_id]);   // invited, no answer — not reminded

    expect($event->reminders()->pluck('minutes_before')->all())->toBe([1440, 60])
        ->and($this->events->sendDueReminders())->toBe(0);

    $this->travel(30)->hours();   // 18 hours before the start: the "day before" reminder is due, the "hour before" one is not

    expect($this->events->sendDueReminders())->toBe(1)
        ->and($this->events->sendDueReminders())->toBe(0)
        ->and(EventReminder::query()->whereNotNull('sent_at')->sole()->recipients)->toBe(2)
        ->and(journalCount('events.reminder.sent'))->toBe(1);
    Notification::assertSentToTimes($o->a1, EventNotice::class, 1);
    Notification::assertSentTo($o->a2, EventNotice::class, fn (EventNotice $notice) => $notice->kind === EventNotice::REMINDER && $notice->category() === 'event_reminders');
    Notification::assertNotSentTo($o->regionHead, EventNotice::class);
    Notification::assertNotSentTo($o->orgHead, EventNotice::class, fn (EventNotice $notice) => $notice->kind === EventNotice::REMINDER);

    $this->artisan('events:tick')->expectsOutputToContain('Reminders sent: 0')->assertSuccessful();
});

it('tells the people going about a new time and about a cancellation', function () {
    Notification::fake();
    $o = $this->org;
    $event = ($this->event)($o->a1, ['reminder_minutes' => [120]]);
    $this->people->invite($o->a1, $event, [$o->a2->person_id, $o->b1->person_id, $o->headA->person_id]);
    $this->people->respond($o->b1, $event, EventAttendee::DECLINED);

    $this->events->update($o->a1, $event, ['title' => 'Întâlnire mutată', 'starts_at' => now()->addDays(3)->setTime(19, 0)]);

    expect($event->fresh())->title->toBe('Întâlnire mutată')
        ->and((int) round($event->fresh()->starts_at->diffInMinutes($event->fresh()->ends_at)))->toBe(60)
        ->and($event->reminders()->sole()->remind_at->equalTo($event->fresh()->starts_at->copy()->subMinutes(120)))->toBeTrue()
        ->and(fn () => $this->events->update($o->a2, $event->fresh(), ['title' => 'X']))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->events->cancel($o->a1, $event->fresh(), ' '))->toThrow(EventRuleViolation::class);
    Notification::assertSentTo($o->a2, EventNotice::class, fn (EventNotice $notice) => $notice->kind === EventNotice::CHANGED);
    Notification::assertNotSentTo($o->b1, EventNotice::class, fn (EventNotice $notice) => $notice->kind === EventNotice::CHANGED);

    $this->events->cancel($o->headA, $event->fresh(), 'Sala este ocupată');

    expect($event->fresh())->cancel_reason->toBe('Sala este ocupată')
        ->and($event->reminders()->count())->toBe(0)
        ->and(journalCount('events.event.cancelled'))->toBe(1)
        ->and(fn () => $this->people->respond($o->a2, $event->fresh(), EventAttendee::GOING))->toThrow(EventRuleViolation::class)
        ->and(fn () => $this->events->update($o->a1, $event->fresh(), ['title' => 'X']))->toThrow(EventRuleViolation::class);
    Notification::assertSentTo($o->a2, EventNotice::class, fn (EventNotice $notice) => $notice->kind === EventNotice::CANCELLED && $notice->note === 'Sala este ocupată');
});

it('creates a recurring event as a series of ordinary events', function () {
    $o = $this->org;
    $start = now()->addDays(2)->setTime(18, 0);

    $first = ($this->event)($o->a1, ['title' => 'Ședința săptămânală', 'starts_at' => $start, 'recurrence' => ['frequency' => 'weekly', 'until' => $start->copy()->addWeeks(3)]]);
    $series = Event::query()->where('series_id', $first->series_id)->orderBy('starts_at')->get();

    expect($series)->toHaveCount(4)
        ->and($series->map(fn (Event $event): string => $event->starts_at->toDateString())->all())
        ->toBe([$start->toDateString(), $start->copy()->addWeek()->toDateString(), $start->copy()->addWeeks(2)->toDateString(), $start->copy()->addWeeks(3)->toDateString()])
        ->and(EventReminder::query()->count())->toBe(8)
        ->and(fn () => ($this->event)($o->a1, ['recurrence' => ['frequency' => 'daily', 'until' => now()->addDays(200)]]))->toThrow(EventRuleViolation::class)
        ->and(fn () => ($this->event)($o->a1, ['recurrence' => ['frequency' => 'yearly', 'until' => now()->addYear()]]))->toThrow(EventRuleViolation::class);

    // "This and the following": the time of day moves, every occurrence keeps its date.
    $this->events->update($o->a1, $series[1], ['starts_at' => $series[1]->starts_at->copy()->setTime(19, 30)], following: true);
    $this->events->cancel($o->a1, $series[3]->fresh(), 'Sărbătoare', following: true);

    $fresh = Event::query()->where('series_id', $first->series_id)->orderBy('starts_at')->get();
    expect($fresh->map(fn (Event $event): string => $event->starts_at->format('H:i'))->all())->toBe(['18:00', '19:30', '19:30', '19:30'])
        ->and($fresh->map(fn (Event $event): bool => $event->isCancelled())->all())->toBe([false, false, false, true]);
});

it('publishes the results and a photo report after the event has begun', function () {
    Storage::fake('local');
    Notification::fake();
    $o = $this->org;
    $event = ($this->event)($o->a1, ['starts_at' => now()->addHour()]);
    $this->people->invite($o->a1, $event, [$o->a2->person_id, $o->b1->person_id]);
    $this->people->respond($o->a2, $event, EventAttendee::GOING);
    $photo = tempnam(sys_get_temp_dir(), 'photo');
    file_put_contents($photo, 'jpeg');

    expect(fn () => $this->events->publishResults($o->a1, $event, 'Prea devreme'))->toThrow(EventRuleViolation::class);

    $this->travel(3)->hours();
    $this->events->publishResults($o->a1, $event, ' Au venit 12 persoane. ', photos: [['source' => $photo, 'name' => 'sala.jpg', 'mime' => 'image/jpeg']]);
    $attachment = $event->attachments()->sole();

    expect($event->fresh())->results->toBe('Au venit 12 persoane.')->results_by_user_id->toBe($o->a1->id)
        ->and($attachment)->kind->toBe('photo')->original_name->toBe('sala.jpg')
        ->and($this->events->attachmentPath($o->b1, $attachment))->toEndWith('.jpg')
        ->and(fn () => $this->events->attachmentPath($o->balti1, $attachment))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->events->publishResults($o->a2, $event, 'X'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->events->publishResults($o->a1, $event->fresh(), null))->not->toThrow(EventRuleViolation::class)
        ->and(journalCount('events.results.published'))->toBe(2);
    // Told once, and only to those who were going or were there.
    Notification::assertSentToTimes($o->a2, EventNotice::class, 2);
    Notification::assertNotSentTo($o->b1, EventNotice::class, fn (EventNotice $notice) => $notice->kind === EventNotice::RESULTS);
});

it('exports to external calendars only what the owner of the feed may see', function () {
    $o = $this->org;
    $calendar = app(CalendarExport::class);
    $mine = ($this->event)($o->a1, ['title' => 'Ședință, sala 2; etaj 3', 'location' => 'Sediu', 'description' => "Linia 1\nLinia 2"]);
    $regional = ($this->event)($o->headA, ['title' => 'Adunare Centru', 'visibility' => Event::REGIONAL, 'territory_ids' => [$o->centru->id]]);
    $botanica = ($this->event)($o->headB, ['title' => 'Adunare Botanica', 'visibility' => Event::REGIONAL, 'territory_ids' => [$o->botanica->id]]);
    $secret = $this->social->group($o->b1, 'Secret', Group::SECRET);

    $ics = $calendar->ics([$mine], 'Test');

    expect($calendar->googleUrl($mine))->toContain('action=TEMPLATE')->toContain($mine->starts_at->copy()->utc()->format('Ymd\THis\Z'))->toContain('location=Sediu')
        ->and($ics)->toContain('BEGIN:VEVENT')->toContain('SUMMARY:Ședință\, sala 2\; etaj 3')->toContain('DESCRIPTION:Linia 1\nLinia 2')
        ->toContain('DTSTART:'.$mine->starts_at->copy()->utc()->format('Ymd\THis\Z'))->toContain("END:VCALENDAR\r\n");

    ['feed' => $personal, 'token' => $token] = $calendar->createFeed($o->a1, CalendarFeed::PERSONAL);
    ['feed' => $territory] = $calendar->createFeed($o->a1, CalendarFeed::TERRITORY, $o->chisinau->id);

    expect($personal->token_hash)->not->toBe($token)
        ->and($calendar->feedByToken($token)?->id)->toBe($personal->id)
        ->and($calendar->feedByToken('wrong'))->toBeNull()
        ->and($calendar->feedEvents($personal)->pluck('id')->all())->toBe([$mine->id])
        // The feed of the whole region still shows only what its owner sees: Centru, not Botanica.
        ->and($calendar->feedEvents($territory)->pluck('id')->all())->toBe([$regional->id])
        ->and(fn () => $calendar->createFeed($o->a1, CalendarFeed::GROUP, $secret->id))->toThrow(AuthorizationException::class)
        ->and(fn () => $calendar->revokeFeed($o->a2, $personal))->toThrow(AuthorizationException::class)
        ->and(journalCount('events.feed.created'))->toBe(2);

    $calendar->revokeFeed($o->a1, $personal);
    expect($calendar->feedByToken($token))->toBeNull();

    ['token' => $other] = $calendar->createFeed($o->a2, CalendarFeed::PERSONAL);
    app(SetUserActive::class)($o->admin, $o->a2, false);
    expect($calendar->feedByToken($other))->toBeNull();
});
