<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\CRM\Models\Interaction;
use App\Domain\Events\Actions\EventParticipation;
use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\CalendarExport;
use App\Domain\Events\EventVisibility;
use App\Domain\Events\Models\CalendarFeed;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Events\Models\EventReminder;
use App\Domain\Events\Notifications\EventNotice;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Announcements;
use App\Domain\Notifications\Exceptions\NotificationRuleViolation;
use App\Domain\Notifications\Models\AnnouncementReceipt;
use App\Domain\Notifications\Models\NotificationDigest;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Notifications\Preferences;
use App\Domain\Social\Notifications\SocialNotice;
use App\Domain\Tasks\Notifications\TaskNotice;
use App\Filament\Resources\Events\Pages\ListEvents;
use Database\Seeders\Demo\CrmDemoSeeder as Crm;
use Database\Seeders\Demo\EventsDemoSeeder as Events;
use Database\Seeders\Demo\NotificationsDemoSeeder as Notices;
use Database\Seeders\Demo\Personas;
use Database\Seeders\Demo\SocialDemoSeeder as Social;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * IMPLEMENTATION-PLAN 5.3 — events and notifications on the demo world (docs/demo/README.md § Мероприятия).
 */

function seesEvent(string $persona, string $title): bool
{
    app(AuthorizationService::class)->forget();

    return app(EventVisibility::class)->canSee(Personas::user($persona), Events::event($title));
}

/**
 * Everything a persona was ever told, as one string — to look for what must not be there.
 */
function toldTo(string $persona): string
{
    return Personas::user($persona)->notifications()->get()->map(fn ($notification): string => json_encode($notification->data, JSON_UNESCAPED_UNICODE))->implode("\n");
}

it('keeps an invitation to a closed event out of sight of everyone outside it', function () {
    $private = Events::event(Events::PRIVATE);
    $secret = Events::event(Events::SECRET);
    $planning = Events::event(Events::GROUP_PLANNING);

    expect(seesEvent('branch_a_employee_1', Events::PRIVATE))->toBeTrue()            // invited
        ->and(seesEvent('branch_a_employee_2', Events::PRIVATE))->toBeFalse()        // same branch, not invited
        ->and(seesEvent('branch_a_employee_3', Events::PRIVATE))->toBeFalse()        // the invitation was withdrawn
        ->and(seesEvent('chisinau_head', Events::PRIVATE))->toBeFalse()              // not even the head above the organizer
        ->and(seesEvent('super_admin', Events::PRIVATE))->toBeFalse()
        // An event of the secret group: for its members only.
        ->and(seesEvent('chisinau_head', Events::SECRET))->toBeTrue()
        ->and(seesEvent('branch_a_head', Events::SECRET))->toBeFalse()               // invited to the group, has not accepted
        ->and(seesEvent('super_admin', Events::SECRET))->toBeFalse()
        // An event of the closed group: Olga's request to join is still waiting.
        ->and(seesEvent('branch_a_employee_2', Events::GROUP_PLANNING))->toBeTrue()
        ->and(seesEvent('branch_b_employee_1', Events::GROUP_PLANNING))->toBeFalse()
        // Whoever does not see an event does not run it, whatever the scope of their role.
        ->and(fn () => app(ManageEvents::class)->cancel(Personas::user('chisinau_head'), $private, 'Motiv'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(EventParticipation::class)->respond(Personas::user('branch_a_employee_2'), $private, EventAttendee::GOING))->toThrow(AuthorizationException::class);

    $maria = Personas::user('branch_a_employee_2');
    $this->actingAs($maria);
    Livewire::test(ListEvents::class)->assertCanNotSeeTableRecords([$private, $secret])->assertCanSeeTableRecords([$planning])
        ->searchTable('delegației')->assertCountTableRecords(0);
    $this->get('/admin/events/'.$private->id)->assertNotFound();
    $this->get('/admin/events/'.$secret->id)->assertNotFound();
    $this->get(route('events.ics', $private))->assertNotFound();
    $this->get('/admin/calendar?scope=all&month='.$private->starts_at->format('Y-m'))->assertOk()->assertDontSee(Events::PRIVATE)->assertDontSee(Events::SECRET);
    expect(app(CalendarExport::class)->scoped($maria, 'all')->pluck('title')->all())->not->toContain(Events::PRIVATE, Events::SECRET)
        ->and(toldTo('branch_a_employee_2'))->not->toContain(Events::PRIVATE)->not->toContain(Events::SECRET);
});

it('shows a regional event where the territories overlap, and not in another region', function () {
    expect(seesEvent('branch_b_employee_1', Events::ASSEMBLY))->toBeTrue()
        ->and(seesEvent('balti_employee_1', Events::ASSEMBLY))->toBeFalse()
        ->and(seesEvent('balti_employee_1', Events::BALTI))->toBeTrue()
        ->and(seesEvent('north_south_employee', Events::BALTI))->toBeTrue()
        ->and(seesEvent('branch_a_employee_1', Events::BALTI))->toBeFalse()
        ->and(seesEvent('branch_b_employee_1', Events::CANVASSING))->toBeFalse()
        ->and(seesEvent('chisinau_head', Events::CANVASSING))->toBeTrue()
        // An employee holds private events and events of their groups; a head — events of their own territories.
        ->and(fn () => app(ManageEvents::class)->create(Personas::user('branch_a_employee_1'), [
            'title' => 'X', 'type_code' => 'meeting', 'starts_at' => now()->addDay(), 'visibility' => Event::REGIONAL,
            'territory_ids' => [Personas::territory('chisinau/sectorul-centru')->id],
        ]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManageEvents::class)->create(Personas::user('branch_a_head'), [
            'title' => 'X', 'type_code' => 'meeting', 'starts_at' => now()->addDay(), 'visibility' => Event::REGIONAL,
            'territory_ids' => [Personas::territory('chisinau/sectorul-botanica')->id],
        ]))->toThrow(AuthorizationException::class);
});

it('empties a notification when its reader loses access to what it was about', function () {
    $sergiu = Personas::user('branch_a_employee_3');
    $event = Events::event(Events::PRIVATE);
    $notice = $sergiu->notifications()->where('subject_type', 'event')->where('subject_id', $event->id)->sole();

    expect($notice->data['title'])->toBe(__('notifications.retracted'))
        ->and($notice->data['actions'])->toBe([])
        ->and($notice->getAttribute('retracted_at'))->not->toBeNull()
        ->and(toldTo('branch_a_employee_3'))->not->toContain(Events::PRIVATE)
        // Ion is still invited: his notification has its content and its link.
        ->and(toldTo('branch_a_employee_1'))->toContain(Events::PRIVATE);

    $this->actingAs($sergiu)->get('/admin/notification-center')->assertOk()->assertSee(__('notifications.retracted'))->assertDontSee(Events::PRIVATE);

    // The same again, live: invited — sees and is told; withdrawn — neither.
    $ana = Personas::user('branch_a_head');
    app(EventParticipation::class)->invite($ana, $event, [$sergiu->person_id]);
    expect(toldTo('branch_a_employee_3'))->toContain(Events::PRIVATE);
    app(EventParticipation::class)->uninvite($ana, $event, $sergiu->person);
    expect(toldTo('branch_a_employee_3'))->not->toContain(Events::PRIVATE)
        ->and(seesEvent('branch_a_employee_3', Events::PRIVATE))->toBeFalse();

    // A notice waiting in the queue is not delivered once its reader cannot see the event.
    expect((new EventNotice(EventNotice::INVITED, $event))->shouldSend($sergiu, 'database'))->toBeFalse()
        ->and((new EventNotice(EventNotice::INVITED, $event))->shouldSend(Personas::user('branch_a_employee_1'), 'database'))->toBeTrue();
});

it('says in a digest only what its reader may see', function () {
    $summaryOf = fn (string $persona, string $frequency): string => json_encode(
        NotificationDigest::query()->where('user_id', Personas::user($persona)->id)->where('frequency', $frequency)->sole()->summary, JSON_UNESCAPED_UNICODE);

    expect($summaryOf('balti_employee_1', 'weekly'))->toContain(Social::WEEKLY_NEWS)->not->toContain(Social::REPORTED)->not->toContain(Events::SECRET)
        ->and($summaryOf('branch_a_employee_1', 'weekly'))->toContain(Social::WEEKLY_NEWS)->not->toContain(Events::SECRET)
        // Maria replaced the weekly digest by a daily one; heads get the daily one by the default of their role.
        ->and(NotificationDigest::query()->where('user_id', Personas::user('branch_a_employee_2')->id)->pluck('frequency')->all())->toBe(['daily'])
        ->and(NotificationDigest::query()->where('user_id', Personas::user('branch_a_head')->id)->pluck('frequency')->all())->toEqualCanonicalizing(['weekly', 'daily'])
        ->and(NotificationDigest::query()->where('user_id', Personas::user('branch_a_employee_1')->id)->pluck('frequency')->all())->toBe(['weekly']);
});

it('sends reminders and digests through the scheduler and the queue, and never twice', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");
    expect($commands)->toContain('events:tick')->toContain('notifications:digest daily')->toContain('notifications:digest weekly');

    // The demo world: both reminders of the past event went out once, to the three who were going or interested.
    $canvassing = Events::event(Events::CANVASSING);
    $remindersOf = fn (string $persona): int => Personas::user($persona)->notifications()->where('category', 'event_reminders')->where('subject_id', $canvassing->id)->count();
    expect(EventReminder::query()->where('event_id', $canvassing->id)->whereNotNull('sent_at')->pluck('recipients')->all())->toBe([3, 3])
        ->and($remindersOf('branch_a_employee_1'))->toBe(2)
        ->and($remindersOf('branch_a_employee_3'))->toBe(2)
        ->and($remindersOf('volunteer'))->toBe(0)                 // declined
        ->and(JournalEntry::query()->where('event_type', 'events.reminder.sent')->count())->toBe(2);

    // The coming assembly: the reminder "a day before" falls due, is queued once, and a second run adds nothing.
    $assembly = Events::event(Events::ASSEMBLY);
    $this->travelTo($assembly->starts_at->copy()->subHours(23));
    Queue::fake();
    $this->artisan('events:tick')->expectsOutputToContain('Reminders sent: 1')->assertSuccessful();
    $queued = Queue::pushed(SendQueuedNotifications::class)->count();
    $this->artisan('events:tick')->expectsOutputToContain('Reminders sent: 0')->assertSuccessful();

    // Four going and two interested — the one who declined is not reminded. One job per person and channel:
    // six in the system and five letters, because Ion switched the letters about reminders off.
    expect($queued)->toBe(11)
        ->and(Queue::pushed(SendQueuedNotifications::class)->count())->toBe($queued)
        ->and(EventReminder::query()->where('event_id', $assembly->id)->whereNotNull('sent_at')->sole()->recipients)->toBe(6);

    // Digests: the demo world has them; another run for the same period sends none and stores none.
    $digests = NotificationDigest::query()->count();
    $this->travelBack();
    $this->artisan('notifications:digest weekly')->expectsOutputToContain('Digests sent: 0')->assertSuccessful();
    $this->artisan('notifications:digest daily')->expectsOutputToContain('Digests sent: 0')->assertSuccessful();
    expect(NotificationDigest::query()->count())->toBe($digests)
        ->and(NotificationDigest::query()->selectRaw('user_id, frequency, period_start, count(*) as n')->groupBy('user_id', 'frequency', 'period_start')->having('n', '>', 1)->count())->toBe(0);
});

it('has a past event with attendance, results and a photo report, feeding the CRM', function () {
    $event = Events::event(Events::CANVASSING);
    $doina = Crm::person('doina');

    expect($event->hasStarted())->toBeTrue()
        ->and($event->results)->toContain('420 de pliante')
        ->and($event->attachments()->pluck('kind')->all())->toEqualCanonicalizing(['file', 'photo'])
        ->and(EventAttendee::query()->where('event_id', $event->id)->where('attended', true)->count())->toBe(5)
        // A supporter without an account was there: the visit is a fact in her feed, linked to the event.
        ->and(Interaction::query()->where('person_id', $doina->id)->where('kind_code', 'event_visit')->where('subject_id', $event->id)->sole()->summary)->toBe(Events::CANVASSING)
        ->and(EventAttendee::query()->where('event_id', $event->id)->where('person_id', Personas::user('volunteer')->person_id)->sole())
        ->rsvp->toBe(EventAttendee::DECLINED)->attended->toBeNull();

    $photo = route('events.attachment', $event->attachments()->where('kind', 'photo')->sole());
    $this->actingAs(Personas::user('branch_a_employee_1'))->get($photo)->assertOk();
    $this->flushSession();
    $this->actingAs(Personas::user('balti_employee_1'))->get($photo)->assertForbidden();
});

it('has a coming event with all three answers, a weekly series and a cancelled event', function () {
    $assembly = Events::event(Events::ASSEMBLY);
    $answers = EventAttendee::query()->where('event_id', $assembly->id)->get()->countBy(fn (EventAttendee $a): string => $a->rsvp ?? 'none');
    $series = Event::query()->where('series_id', Events::event(Events::WEEKLY)->series_id)->orderBy('starts_at')->get();
    $cancelled = Events::event(Events::CANCELLED);

    expect($assembly->starts_at->isFuture())->toBeTrue()
        ->and($answers->all())->toMatchArray(['going' => 4, 'interested' => 2, 'declined' => 1])
        ->and($answers['none'])->toBeGreaterThan(3)
        ->and($series)->toHaveCount(8)
        ->and($series->filter(fn (Event $event): bool => $event->hasStarted())->count())->toBe(3)
        ->and($series->map(fn (Event $event): int => $event->starts_at->dayOfWeek)->unique()->count())->toBe(1)
        ->and($cancelled->cancel_reason)->not->toBeNull()
        ->and($cancelled->reminders()->whereNull('sent_at')->count())->toBe(0)
        ->and(Personas::user('branch_b_employee_1')->notifications()->where('subject_type', 'event')->where('subject_id', $cancelled->id)->pluck('data')->pluck('kind')->all())
        ->toContain('event_cancelled');

    // Olga declined the assembly with a comment that only those who run the event read.
    $this->actingAs(Personas::user('chisinau_head'))->get('/admin/events/'.$assembly->id)->assertOk()->assertSee('В этот день я в командировке');
    $this->flushSession();
    $this->actingAs(Personas::user('branch_a_employee_2'))->get('/admin/events/'.$assembly->id)->assertOk()->assertDontSee('В этот день я в командировке');
});

it('exports to an external calendar what the owner of the subscription sees, and nothing after a revocation', function () {
    $calendar = app(CalendarExport::class);
    $ion = Personas::user('branch_a_employee_1');
    $feed = CalendarFeed::query()->where('user_id', $ion->id)->sole();
    $titles = $calendar->feedEvents($feed)->pluck('title')->all();

    expect($titles)->toContain(Events::PRIVATE, Events::ASSEMBLY, Events::CANVASSING)
        ->not->toContain(Events::SECRET, Events::BALTI, Events::WEEKLY)
        // The region feed of the region head: both sectors, not Bălți.
        ->and($calendar->feedEvents(CalendarFeed::query()->where('user_id', Personas::user('chisinau_head')->id)->sole())->pluck('title')->all())
        ->toContain(Events::ASSEMBLY, Events::CANVASSING, Events::CANCELLED)->not->toContain(Events::BALTI)
        ->and(CalendarFeed::query()->where('user_id', Personas::user('branch_a_employee_2')->id)->sole()->revoked_at)->not->toBeNull();

    ['token' => $token, 'feed' => $new] = $calendar->createFeed($ion, CalendarFeed::PERSONAL);
    $this->get('/calendar/feed/'.$token.'.ics')->assertOk()->assertSee('SUMMARY:'.Events::PRIVATE, false)->assertDontSee(Events::SECRET, false);
    $calendar->revokeFeed($ion, $new);
    $this->get('/calendar/feed/'.$token.'.ics')->assertNotFound();
});

it('keeps a critical notice in front of those who have not confirmed it, and tells the sender who did', function () {
    $announcements = app(Announcements::class);
    $critical = Notices::announcement(Notices::CRITICAL);
    $pending = AnnouncementReceipt::query()->where('announcement_id', $critical->id)->whereNull('acknowledged_at')->pluck('user_id')->all();

    expect($pending)->toEqualCanonicalizing(array_map(fn (string $key): int => Personas::user($key)->id, Notices::NOT_ACKNOWLEDGED))
        ->and($announcements->pendingFor(Personas::user('branch_a_employee_1'))->count())->toBe(0)
        ->and($announcements->maySeeReceipts(Personas::user('security'), $critical))->toBeTrue()
        ->and($announcements->maySeeReceipts(Personas::user('org_head'), $critical))->toBeTrue()
        ->and($announcements->maySeeReceipts(Personas::user('branch_a_head'), $critical))->toBeFalse()
        // A head of a branch sends announcements to her branch only — and no critical ones.
        ->and(Notices::announcement(Notices::BRANCH_ANNOUNCEMENT)->receipts()->pluck('user_id')->all())
        ->toEqualCanonicalizing(User::query()->whereIn('person_id', Personas::unit('branch_a')->memberships()->pluck('person_id'))
            ->where('status', 'active')->whereKeyNot(Personas::user('branch_a_head')->id)->pluck('id')->all())
        ->and(fn () => $announcements->send(Personas::user('branch_a_head'), ['title' => 'X', 'body' => 'Y', 'critical' => true]))->toThrow(AuthorizationException::class);

    $sergiu = Personas::user('branch_a_employee_3');
    $this->actingAs($sergiu)->get('/admin/feed')->assertOk()->assertSee(Notices::CRITICAL);
    $this->post(route('announcements.acknowledge', $critical))->assertRedirect();
    $this->get('/admin/feed')->assertOk()->assertDontSee(Notices::CRITICAL);
    expect($announcements->pendingFor($sergiu)->count())->toBe(0)
        ->and(JournalEntry::query()->where('event_type', 'notifications.critical.acknowledged')->latest('id')->first()->actor_user_id)->toBe($sergiu->id);
});

it('routes notifications by the settings of each person, with the defaults of their roles behind', function () {
    $preferences = app(Preferences::class);
    $ion = Personas::user('branch_a_employee_1');
    $event = Events::event(Events::ASSEMBLY);

    expect((new SocialNotice(SocialNotice::COMMENT, ['name' => 'X']))->via($ion))->toBe(['database', 'mail'])
        ->and((new SocialNotice(SocialNotice::COMMENT, ['name' => 'X']))->via(Personas::user('branch_a_employee_3')))->toBe(['database'])
        ->and((new EventNotice(EventNotice::REMINDER, $event))->via($ion))->toBe(['database'])
        ->and((new EventNotice(EventNotice::INVITED, $event))->via($ion))->toBe(['database', 'mail'])
        ->and((new EventNotice(EventNotice::INVITED, $event))->via(Personas::user('branch_b_employee_1')))->toBe(['database'])
        // The operator of the inbox gets CRM notices by e-mail — the default of her role, changed by the administrator.
        ->and($preferences->enabled(Personas::user('inbox_operator'), 'crm', NotificationCategories::EMAIL))->toBeTrue()
        ->and($preferences->enabled($ion, 'crm', NotificationCategories::EMAIL))->toBeFalse()
        ->and($preferences->enabled(Personas::user('volunteer'), 'digest_weekly', NotificationCategories::EMAIL))->toBeFalse()
        ->and($preferences->enabled(Personas::user('org_head'), 'digest_daily', NotificationCategories::EMAIL))->toBeTrue()
        // A sanction of a moderator cannot be switched off — Dan, who is muted, tried.
        ->and(fn () => $preferences->set(Personas::user('branch_b_employee_2'), 'moderation', NotificationCategories::IN_APP, false))->toThrow(NotificationRuleViolation::class)
        ->and(fn () => $preferences->setDefault(Personas::user('org_head'), Role::query()->where('code', 'employee')->sole(), 'social', NotificationCategories::EMAIL, true))
        ->toThrow(AuthorizationException::class)
        ->and(TaskNotice::class)->toBeString();
});

it('gives every persona read and unread notifications, each stored with its category', function () {
    foreach (User::query()->where('status', 'active')->where('email', 'like', '%@'.config('demo.email_domain'))->get() as $user) {
        if (! app(AuthorizationService::class)->can($user, 'notifications.read')) {
            continue;
        }
        expect($user->readNotifications()->count())->toBeGreaterThan(0, $user->email.' has nothing read')
            ->and($user->unreadNotifications()->count())->toBeGreaterThan(0, $user->email.' has nothing unread');
    }

    expect(Personas::user('branch_a_employee_1')->notifications()->whereNull('category')->count())->toBe(0);
});
