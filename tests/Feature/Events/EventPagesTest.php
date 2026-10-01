<?php

use App\Domain\Events\Actions\EventParticipation;
use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\CalendarExport;
use App\Domain\Events\Models\CalendarFeed;
use App\Domain\Events\Models\Event;
use App\Domain\Events\Models\EventAttendee;
use App\Domain\Notifications\Announcements;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Notifications\Preferences;
use App\Filament\Pages\EventCalendar;
use App\Filament\Pages\NotificationDefaults;
use App\Filament\Pages\NotificationInbox;
use App\Filament\Pages\NotificationSettings;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Events\EventResource;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Pages\ViewEvent;
use Livewire\Livewire;
use Tests\Support\SocialFixture;

/*
 * The screens of phase 5 are a thin layer over Events and Notifications: they show what the domain lets the
 * viewer see and call the domain actions. These tests go through the pages the way a person does.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->event = fn ($organizer, array $data = []): Event => app(ManageEvents::class)->create($organizer, [
        'title' => 'Întâlnire', 'type_code' => 'meeting', 'starts_at' => now()->addDays(2)->setTime(18, 0), ...$data,
    ]);
});

it('creates an event from the list and shows only visible events there', function () {
    $o = $this->org;
    $this->actingAs($o->headA);

    Livewire::test(ListEvents::class)->callAction('create', data: [
        'title' => 'Adunarea sectorului Centru', 'type_code' => 'assembly', 'starts_at' => now()->addDays(3)->setTime(18, 0)->toDateTimeString(),
        'visibility' => Event::REGIONAL, 'territory_ids' => [$o->centru->id], 'reminder_minutes' => [1440],
        'frequency' => 'weekly', 'until' => now()->addDays(17)->toDateString(),
    ])->assertHasNoActionErrors();
    $private = ($this->event)($o->a1, ['title' => 'Ședință privată']);

    expect(Event::query()->where('title', 'Adunarea sectorului Centru')->count())->toBe(3)
        ->and(Event::query()->whereNotNull('series_id')->distinct()->count('series_id'))->toBe(1);
    $regional = Event::query()->where('title', 'Adunarea sectorului Centru')->orderBy('starts_at')->get();

    $this->flushSession();
    $this->actingAs($o->a2);
    Livewire::test(ListEvents::class)->assertCanSeeTableRecords($regional)->assertCanNotSeeTableRecords([$private]);
    $this->get('/admin/events/'.$regional[0]->id)->assertOk()->assertSee('Adunarea sectorului Centru')->assertSee(__('events.ui.add_to_google'));
    $this->get('/admin/events/'.$private->id)->assertNotFound();

    $this->flushSession();
    $this->actingAs($o->b1);
    Livewire::test(ListEvents::class)->assertCountTableRecords(0);
    $this->get('/admin/events/'.$regional[0]->id)->assertNotFound();
    // An employee is offered only what they may hold: no "territory", no "whole organization".
    expect(EventResource::visibilityOptions())->toHaveKeys([Event::PRIVATE])->not->toHaveKeys([Event::REGIONAL, Event::PUBLIC]);
});

it('answers, invites, marks attendance and publishes the results from the page of the event', function () {
    $o = $this->org;
    $event = ($this->event)($o->a1, ['title' => 'Ședință de lucru', 'starts_at' => now()->addHour()]);

    $this->actingAs($o->a1);
    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->callAction('invite', data: ['person_ids' => [$o->a2->person_id, $o->b1->person_id]])
        ->callAction('respond', data: ['rsvp' => EventAttendee::GOING, 'rsvp_comment' => 'Deschid sala'])
        ->assertHasNoActionErrors()
        ->assertSee($o->b1->person->fullName())->assertSee('Deschid sala');

    $this->flushSession();
    $this->actingAs($o->a2);
    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->callAction('respond', data: ['rsvp' => EventAttendee::DECLINED])
        // A guest neither sees who declined, nor runs the event.
        ->assertDontSee($o->b1->person->fullName())
        ->call('uninvite', $o->b1->person_id)
        ->call('markAttendance', $o->a1->person_id, true);

    expect(EventAttendee::query()->where('event_id', $event->id)->count())->toBe(3)
        ->and(EventAttendee::query()->where('event_id', $event->id)->where('attended', true)->count())->toBe(0);

    $this->travel(2)->hours();
    $this->flushSession();
    $this->actingAs($o->a1);
    Livewire::test(ViewEvent::class, ['record' => $event->id])
        ->call('markAttendance', $o->b1->person_id, true)
        ->callAction('results', data: ['results' => 'Am stabilit planul pe luna viitoare.'])
        ->callAction('edit', data: ['title' => 'Ședință de lucru (încheiată)', 'type_code' => 'meeting', 'starts_at' => $event->starts_at->toDateTimeString(), 'visibility' => Event::PRIVATE])
        ->assertHasNoActionErrors()
        ->assertSee('Am stabilit planul pe luna viitoare.');

    expect($event->fresh())->title->toBe('Ședință de lucru (încheiată)')->results->toBe('Am stabilit planul pe luna viitoare.')
        ->and(EventAttendee::query()->where('event_id', $event->id)->where('attended', true)->pluck('person_id')->all())->toBe([$o->b1->person_id]);

    Livewire::test(ViewEvent::class, ['record' => $event->id])->callAction('cancel', data: ['reason' => 'Greșeală']);
    expect($event->fresh()->isCancelled())->toBeTrue();
});

it('gives the .ics file and the iCal feed only what their reader may see', function () {
    $o = $this->org;
    $event = ($this->event)($o->a1, ['title' => 'Ședință privată']);
    app(EventParticipation::class)->invite($o->a1, $event, [$o->a2->person_id]);
    ['token' => $token] = app(CalendarExport::class)->createFeed($o->a2, CalendarFeed::PERSONAL);

    $this->get(route('events.ics', $event))->assertRedirect();
    $this->actingAs($o->a2)->get(route('events.ics', $event))->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('SUMMARY:Ședință privată', false);
    $this->flushSession();
    $this->actingAs($o->b1)->get(route('events.ics', $event))->assertNotFound();

    // The feed needs no session — the token is the key.
    auth()->logout();
    $this->flushSession();
    $this->get('/calendar/feed/'.$token.'.ics')->assertOk()->assertSee('SUMMARY:Ședință privată', false);
    $this->get('/calendar/feed/'.str_repeat('a', 48).'.ics')->assertNotFound();

    // The invitation is withdrawn: the same address no longer shows the event.
    app(EventParticipation::class)->uninvite($o->a1, $event, $o->a2->person);
    $this->get('/calendar/feed/'.$token.'.ics')->assertOk()->assertDontSee('Ședință privată', false);
    expect(CalendarFeed::query()->sole()->last_used_at)->not->toBeNull();
});

it('shows the calendar: own events with task deadlines, a group, a territory — and manages subscriptions', function () {
    $o = $this->org;
    $mine = ($this->event)($o->a1, ['title' => 'Evenimentul meu', 'starts_at' => now()->startOfMonth()->addDays(10)->setTime(18, 0)]);
    ($this->event)($o->headA, ['title' => 'Eveniment regional', 'starts_at' => now()->startOfMonth()->addDays(11)->setTime(18, 0), 'visibility' => Event::REGIONAL, 'territory_ids' => [$o->centru->id]]);

    $this->actingAs($o->a1);
    $this->get('/admin/calendar')->assertOk()->assertSee('Evenimentul meu')->assertDontSee('Eveniment regional');
    $this->get('/admin/calendar?scope=all')->assertOk()->assertSee('Eveniment regional');
    $this->get('/admin/calendar?scope=territory&territory='.$o->botanica->id)->assertOk()->assertDontSee('Eveniment regional');

    Livewire::test(EventCalendar::class)->callAction('subscribe', data: ['scope' => CalendarFeed::PERSONAL])->assertHasNoActionErrors();
    $feed = CalendarFeed::query()->sole();
    Livewire::test(EventCalendar::class)->assertSee(__('events.ui.scopes.personal'))->call('revokeFeed', $feed->id);

    expect($feed->fresh()->revoked_at)->not->toBeNull();

    // Another person cannot revoke it through the page.
    ['feed' => $other] = app(CalendarExport::class)->createFeed($o->a2, CalendarFeed::PERSONAL);
    Livewire::test(EventCalendar::class)->call('revokeFeed', $other->id);
    expect($other->fresh()->revoked_at)->toBeNull()->and($mine->id)->toBeInt();
});

it('runs the notification center: reading, settings, the critical bar', function () {
    $o = $this->org;
    $event = ($this->event)($o->a1, ['title' => 'Ședință de lucru']);
    app(EventParticipation::class)->invite($o->a1, $event, [$o->a2->person_id]);
    $critical = app(Announcements::class)->send($o->orgHead, ['title' => 'Schimbați parolele', 'body' => 'Până diseară.', 'critical' => true]);

    $this->actingAs($o->a2);
    $this->get('/admin/notification-center')->assertOk()->assertSee('Ședință de lucru')->assertSee('Schimbați parolele')
        ->assertSee(__('notifications.acknowledge'));
    // The bar is on every page until the notice is confirmed.
    $this->get('/admin/feed')->assertOk()->assertSee('Până diseară.');

    Livewire::test(NotificationInbox::class)->call('markAllRead')->call('acknowledge', $critical->id);
    expect($o->a2->unreadNotifications()->count())->toBe(0)
        ->and(app(Announcements::class)->pendingFor($o->a2)->count())->toBe(0);
    $this->get('/admin/feed')->assertOk()->assertDontSee('Până diseară.');

    $this->post(route('announcements.acknowledge', $critical))->assertRedirect();

    Livewire::test(NotificationSettings::class)->call('toggle', 'social', NotificationCategories::EMAIL)->call('toggle', 'critical', NotificationCategories::EMAIL);
    app(Preferences::class)->forget();
    expect(app(Preferences::class)->enabled($o->a2, 'social', NotificationCategories::EMAIL))->toBeTrue()
        ->and(app(Preferences::class)->enabled($o->a2, 'critical', NotificationCategories::EMAIL))->toBeTrue();

    Livewire::test(NotificationSettings::class)->call('resetToDefaults');
    app(Preferences::class)->forget();
    expect(app(Preferences::class)->enabled($o->a2, 'social', NotificationCategories::EMAIL))->toBeFalse();
});

it('lets the administrator set defaults by role and a head send announcements to their own area', function () {
    $o = $this->org;

    $this->actingAs($o->admin);
    Livewire::test(NotificationDefaults::class)->set('role', 'employee')->call('setDefault', 'social', NotificationCategories::EMAIL, 'on');
    app(Preferences::class)->forget();
    expect(app(Preferences::class)->enabled($o->b1, 'social', NotificationCategories::EMAIL))->toBeTrue();

    $this->flushSession();
    $this->actingAs($o->headA);
    $this->get('/admin/notification-defaults')->assertForbidden();
    Livewire::test(ListAnnouncements::class)->callAction('send', data: ['title' => 'Sediul este închis luni', 'body' => 'Lucrăm de la distanță.'])->assertHasNoActionErrors();
    $announcement = Announcement::query()->sole();

    expect($announcement)->is_critical->toBeFalse()->recipients->toBe(2);
    Livewire::test(ListAnnouncements::class)->assertCanSeeTableRecords([$announcement]);

    $this->flushSession();
    $this->actingAs($o->headB);
    Livewire::test(ListAnnouncements::class)->assertCanNotSeeTableRecords([$announcement]);
    $this->flushSession();
    $this->actingAs($o->a1)->get('/admin/announcements')->assertForbidden();
});
