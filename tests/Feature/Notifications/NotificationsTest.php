<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Notifications\GroupNotice;
use App\Domain\Notifications\Announcements;
use App\Domain\Notifications\Digests;
use App\Domain\Notifications\Exceptions\NotificationRuleViolation;
use App\Domain\Notifications\Models\AnnouncementReceipt;
use App\Domain\Notifications\Models\NotificationDigest;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Notifications\NotificationCenter;
use App\Domain\Notifications\Notifications\AnnouncementNotice;
use App\Domain\Notifications\Notifications\DigestNotice;
use App\Domain\Notifications\Preferences;
use App\Domain\Notifications\SyncNotificationDefaults;
use App\Domain\People\Models\Person;
use App\Domain\Social\Actions\ManageComments;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Moderation;
use App\Domain\Social\Notifications\SocialNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SocialFixture;

/*
 * ФО §6.13, ТЗ §37 — the notification center: channels by the person's own settings with defaults by role,
 * announcements and critical notices with a confirmation of reading, digests, and no content for those who
 * lost access to the subject of a notification.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->preferences = app(Preferences::class);
    $this->channels = fn ($user, string $kind = SocialNotice::COMMENT): array => (new SocialNotice($kind, ['name' => 'X'], 1))->via($user);
});

it('sends a notification where its recipient asked, with the defaults of the category behind', function () {
    $o = $this->org;

    expect(($this->channels)($o->a1))->toBe(['database'])                               // "social": in the system only
        ->and((new GroupNotice(GroupNotice::INVITED, 'G', 1))->via($o->a1))->toBe(['database'])
        ->and($this->preferences->enabled($o->a1, 'tasks', NotificationCategories::EMAIL))->toBeTrue()
        ->and($this->preferences->enabled($o->a1, 'digest_daily', NotificationCategories::IN_APP))->toBeFalse();

    $this->preferences->set($o->a1, 'social', NotificationCategories::EMAIL, true);
    expect(($this->channels)($o->a1))->toBe(['database', 'mail']);

    $this->preferences->set($o->a1, 'social', NotificationCategories::IN_APP, false);
    $this->preferences->set($o->a1, 'social', NotificationCategories::EMAIL, false);
    expect(($this->channels)($o->a1))->toBe([])
        // A sanction of a moderator is not something to unsubscribe from.
        ->and(($this->channels)($o->a1, SocialNotice::MUTED))->toBe(['database', 'mail'])
        ->and(fn () => $this->preferences->set($o->a1, 'moderation', NotificationCategories::IN_APP, false))->toThrow(NotificationRuleViolation::class)
        ->and(fn () => $this->preferences->set($o->a1, 'security', NotificationCategories::EMAIL, false))->toThrow(NotificationRuleViolation::class)
        ->and(fn () => $this->preferences->set($o->a1, 'social', NotificationCategories::PUSH, true))->toThrow(NotificationRuleViolation::class)
        ->and(fn () => $this->preferences->set($o->a1, 'weather', NotificationCategories::IN_APP, true))->toThrow(NotificationRuleViolation::class);

    $this->preferences->reset($o->a1);
    expect(($this->channels)($o->a1))->toBe(['database']);
});

it('takes the defaults from the roles of the person, and lets only the administrator change them', function () {
    $o = $this->org;
    $employee = Role::query()->where('code', 'employee')->sole();
    $head = Role::query()->where('code', 'unit_head')->sole();

    $this->preferences->setDefault($o->admin, $employee, 'social', NotificationCategories::EMAIL, true);
    $this->preferences->setDefault($o->admin, $head, 'social', NotificationCategories::IN_APP, false);

    expect(($this->channels)($o->a1))->toBe(['database', 'mail'])
        // Several roles: a channel is on if any of them turns it on — the head is an employee too.
        ->and(($this->channels)($o->headA))->toBe(['mail'])
        ->and($this->preferences->defaultsOf($employee))->toBe(['social' => ['email' => true]])
        ->and(journalCount('notifications.defaults.changed'))->toBe(2)
        ->and(fn () => $this->preferences->setDefault($o->headA, $employee, 'social', NotificationCategories::EMAIL, false))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->preferences->setDefault($o->admin, $employee, 'critical', NotificationCategories::EMAIL, false))->toThrow(NotificationRuleViolation::class);

    // The person's own choice beats the roles.
    $this->preferences->set($o->a1, 'social', NotificationCategories::EMAIL, false);
    $this->preferences->setDefault($o->admin, $employee, 'social', NotificationCategories::EMAIL, null);

    expect(($this->channels)($o->a1))->toBe(['database'])
        ->and(($this->channels)($o->a2))->toBe(['database'])
        ->and($this->preferences->defaultsOf($employee))->toBe([]);
});

it('gives the starter defaults: a daily digest to those who answer for an area', function () {
    $o = $this->org;

    expect(app(SyncNotificationDefaults::class)())->toBeGreaterThan(0)
        ->and(app(SyncNotificationDefaults::class)())->toBe(0);
    $this->preferences->forget();

    expect($this->preferences->enabled($o->headA, 'digest_daily', NotificationCategories::IN_APP))->toBeTrue()
        ->and($this->preferences->enabled($o->headA, 'digest_daily', NotificationCategories::EMAIL))->toBeFalse()
        ->and($this->preferences->enabled($o->orgHead, 'digest_daily', NotificationCategories::EMAIL))->toBeTrue()
        ->and($this->preferences->enabled($o->a1, 'digest_daily', NotificationCategories::IN_APP))->toBeFalse()
        ->and($this->preferences->enabled($o->a1, 'digest_weekly', NotificationCategories::EMAIL))->toBeTrue();
});

it('writes the letter from the same payload as the bell, in the language of the reader', function () {
    Mail::fake();
    $o = $this->org;
    $o->a2->update(['locale' => 'ru']);
    $this->preferences->set($o->a2, 'groups', NotificationCategories::EMAIL, true);

    $group = app(ManageGroups::class)->create($o->a1, ['name' => 'Voluntari', 'type' => Group::CLOSED]);
    app(ManageGroups::class)->invite($o->a1, $group, $o->a2->person);

    $stored = $o->a2->notifications()->sole();
    $mail = (new GroupNotice(GroupNotice::INVITED, 'Voluntari', $group->id))->toMail($o->a2);

    expect($stored->data['title'])->toBe('Вас пригласили в группу «Voluntari»')
        ->and($stored->getAttribute('category'))->toBe('groups')
        ->and([$stored->getAttribute('subject_type'), (int) $stored->getAttribute('subject_id')])->toBe(['group', $group->id])
        ->and($mail->actionUrl)->toBe(url('/admin/groups'));
});

it('empties what was told about a post when the reader loses sight of it', function () {
    $o = $this->org;
    app(ManageComments::class)->follow($o->a2, $o->a1->person);
    app(ManageComments::class)->follow($o->headA, $o->a1->person);
    $post = $this->social->centruPost('Informație sensibilă');
    $titleOf = fn ($user): string => $user->notifications()->where('subject_type', 'post')->sole()->data['title'];

    expect($titleOf($o->a2))->toContain($o->a1->person->fullName());

    // Narrowed to the author: both followers lose it.
    app(ManagePosts::class)->changeAudience($o->a1, $post, ['visibility' => Post::PRIVATE]);

    expect($titleOf($o->a2))->toBe(__('notifications.retracted'))
        ->and($titleOf($o->headA))->toBe(__('notifications.retracted'))
        ->and($o->a2->notifications()->sole()->data['actions'])->toBe([])
        ->and($o->a2->unreadNotifications()->count())->toBe(0);

    // Hidden by a moderator: the followers lose it, the author is told why and keeps the link.
    $second = $this->social->centruPost('Altă postare');
    app(Moderation::class)->hide($o->headA, $second, 'Neverificat');

    expect($o->a2->notifications()->where('subject_id', $second->id)->sole()->data['title'])->toBe(__('notifications.retracted'))
        ->and($o->a1->notifications()->where('subject_id', $second->id)->sole()->data['kind'])->toBe('social_hidden');
});

it('sends an announcement only to the people of the sender\'s own area', function () {
    Notification::fake();
    $o = $this->org;
    $announcements = app(Announcements::class);

    $announcement = $announcements->send($o->headA, ['title' => ' Sediul este închis luni ', 'body' => 'Lucrăm de la distanță.'], Person::query());

    expect($announcement)->title->toBe('Sediul este închis luni')->is_critical->toBeFalse()->recipients->toBe(2)
        ->and(AnnouncementReceipt::query()->pluck('user_id')->all())->toEqualCanonicalizing([$o->a1->id, $o->a2->id])
        ->and(journalCount('notifications.announcement.sent'))->toBe(1)
        ->and(fn () => $announcements->send($o->a1, ['title' => 'X', 'body' => 'Y']))->toThrow(AuthorizationException::class)
        ->and(fn () => $announcements->send($o->headA, ['title' => ' ', 'body' => 'Y']))->toThrow(NotificationRuleViolation::class)
        ->and(fn () => $announcements->send($o->headA, ['title' => 'X', 'body' => 'Y'], Person::query()->whereKey($o->b1->person_id)))->toThrow(NotificationRuleViolation::class)
        // A unit head does not send critical notices.
        ->and(fn () => $announcements->send($o->headA, ['title' => 'X', 'body' => 'Y', 'critical' => true]))->toThrow(AuthorizationException::class);
    Notification::assertSentTo($o->a1, AnnouncementNotice::class, fn (AnnouncementNotice $notice) => $notice->category() === 'announcements');
    Notification::assertNotSentTo($o->b1, AnnouncementNotice::class);
    Notification::assertNotSentTo($o->headA, AnnouncementNotice::class);
});

it('keeps a critical notice in front of the person until they confirm reading it', function () {
    $o = $this->org;
    $announcements = app(Announcements::class);
    $center = app(NotificationCenter::class);
    // Even someone who turned announcements off gets a critical one, by every channel.
    $this->preferences->set($o->a1, 'announcements', NotificationCategories::IN_APP, false);

    $critical = $announcements->send($o->orgHead, ['title' => 'Schimbați parolele', 'body' => 'Până la sfârșitul zilei.', 'critical' => true]);

    expect((new AnnouncementNotice($critical))->via($o->a1))->toBe(['database', 'mail'])
        ->and($announcements->pendingFor($o->a1)->pluck('id')->all())->toBe([$critical->id])
        ->and($announcements->pendingFor($o->orgHead)->count())->toBe(0)
        ->and($critical->recipients)->toBeGreaterThan(8);

    // Neither opening it nor "mark all as read" counts as a confirmation.
    $notification = $o->a1->notifications()->where('category', 'critical')->sole();
    $center->markRead($o->a1, $notification->id);
    $center->markAllRead($o->a1);

    expect($notification->fresh()->read_at)->toBeNull()
        ->and($announcements->pendingFor($o->a1)->count())->toBe(1);

    $announcements->acknowledge($o->a1, $critical);
    $announcements->acknowledge($o->a1, $critical);

    expect($announcements->pendingFor($o->a1)->count())->toBe(0)
        ->and($notification->fresh()->read_at)->not->toBeNull()
        ->and(journalCount('notifications.critical.acknowledged'))->toBe(1)
        ->and($critical->receipts()->whereNotNull('acknowledged_at')->count())->toBe(1)
        ->and(fn () => $announcements->acknowledge($o->orgHead, $critical))->toThrow(AuthorizationException::class)
        // Who confirmed is seen by the sender and by the others who may send critical notices — not by a recipient.
        ->and($announcements->maySeeReceipts($o->orgHead, $critical))->toBeTrue()
        ->and($announcements->maySeeReceipts($o->admin, $critical))->toBeTrue()
        ->and($announcements->maySeeReceipts($o->a1, $critical))->toBeFalse();
});

it('builds a digest from what the reader may see, and never twice for the same period', function () {
    Notification::fake();
    $o = $this->org;
    $digests = app(Digests::class);
    $this->travelTo(now()->startOfWeek()->addDays(2)->setTime(12, 0));
    $this->social->centruPost('Știri din Centru');
    $this->social->post($o->orgHead, 'Știri pentru toți', ['visibility' => Post::PUBLIC]);
    $this->preferences->set($o->b1, 'digest_weekly', NotificationCategories::IN_APP, false);
    $this->preferences->set($o->b1, 'digest_weekly', NotificationCategories::EMAIL, false);

    $this->travelTo(now()->addWeek()->startOfWeek()->setTime(7, 30));
    $sent = $digests->run(Digests::WEEKLY);

    $linesOf = fn ($user): string => json_encode(NotificationDigest::query()->where('user_id', $user->id)->sole()->summary, JSON_UNESCAPED_UNICODE);
    expect($sent)->toBeGreaterThan(5)
        ->and($linesOf($o->a2))->toContain('Știri din Centru')->toContain('Știri pentru toți')
        // Bălți reads about the public post only.
        ->and($linesOf($o->balti1))->toContain('Știri pentru toți')->not->toContain('Știri din Centru')
        ->and(NotificationDigest::query()->where('user_id', $o->b1->id)->exists())->toBeFalse()
        ->and($digests->run(Digests::WEEKLY))->toBe(0)
        ->and(NotificationDigest::query()->where('user_id', $o->a2->id)->count())->toBe(1);
    Notification::assertSentToTimes($o->a2, DigestNotice::class, 1);
    Notification::assertNotSentTo($o->b1, DigestNotice::class);

    $this->artisan('notifications:digest weekly')->expectsOutputToContain('Digests sent: 0')->assertSuccessful();
    $this->artisan('notifications:digest hourly')->assertFailed();
});

it('lets a person read and mark only their own notifications', function () {
    $o = $this->org;
    $center = app(NotificationCenter::class);
    $group = app(ManageGroups::class)->create($o->a1, ['name' => 'Voluntari', 'type' => Group::CLOSED]);
    app(ManageGroups::class)->invite($o->a1, $group, $o->a2->person);
    app(ManageGroups::class)->invite($o->a1, $group, $o->b1->person);
    $ofA2 = $o->a2->notifications()->sole();

    expect($center->unreadCount($o->a2))->toBe(1)
        ->and($center->for($o->a2, category: 'groups')->count())->toBe(1)
        ->and($center->for($o->a2, category: 'tasks')->count())->toBe(0)
        ->and($center->categoriesOf($o->a2))->toBe(['groups'])
        ->and($center->markRead($o->b1, $ofA2->id))->toBeNull()
        ->and($ofA2->fresh()->read_at)->toBeNull();

    $center->markRead($o->a2, $ofA2->id);

    expect($center->unreadCount($o->a2))->toBe(0)
        ->and($center->for($o->a2, unreadOnly: true)->count())->toBe(0)
        ->and($center->markAllRead($o->b1))->toBe(1);
    app(AuthorizationService::class)->forget();
});
