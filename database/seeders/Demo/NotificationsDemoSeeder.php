<?php

namespace Database\Seeders\Demo;

use App\Domain\Access\Models\Role;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Announcements;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Notifications\NotificationCenter;
use App\Domain\Notifications\Preferences;
use App\Domain\People\Models\Person;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The notification center of the demo world (plan 5.2): different settings of different people and a default
 * changed for a role; an announcement to everyone and one to a branch; a critical notice that four people have
 * not confirmed yet; read and unread notifications for everyone; the weekly and the daily digest.
 *
 * It runs last: by then every earlier seeder has told people what happened in its part of the world.
 */
class NotificationsDemoSeeder extends Seeder
{
    use DemoSteps;

    public const string ANNOUNCEMENT = 'Programul de lucru în perioada sărbătorilor';

    public const string BRANCH_ANNOUNCEMENT = 'Luni sediul filialei A este închis';

    public const string CRITICAL = 'Schimbați parolele până la sfârșitul săptămânii';

    /** Those who have not confirmed reading the critical notice. */
    public const array NOT_ACKNOWLEDGED = ['branch_a_employee_3', 'branch_b_employee_1', 'balti_employee_2', 'volunteer'];

    public static function announcement(string $title): Announcement
    {
        return Announcement::query()->where('title', $title)->firstOrFail();
    }

    public function run(): void
    {
        try {
            $this->at(10, fn () => $this->settings());
            $this->at(6, fn () => $this->announcements());
            $this->at(2, fn () => $this->criticalNotice());
            $this->at(0, fn () => $this->readOlderNotifications());
            $this->at(0, fn () => $this->digests());
        } finally {
            $this->resetClock();
        }
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $step
     * @return T
     */
    private function by(string $key, callable $step): mixed
    {
        $user = Personas::user($key);

        return $this->as($user, fn () => $step($user));
    }

    private function settings(): void
    {
        $preferences = app(Preferences::class);
        $set = fn (string $key, string $category, string $channel, bool $enabled) => $this->by($key, fn (User $user) => $preferences->set($user, $category, $channel, $enabled));

        // Ion wants letters about the feed, and no letters reminding him of events.
        $set('branch_a_employee_1', 'social', NotificationCategories::EMAIL, true);
        $set('branch_a_employee_1', 'event_reminders', NotificationCategories::EMAIL, false);
        // Maria replaced the weekly digest by a daily one, in the system only.
        $set('branch_a_employee_2', 'digest_weekly', NotificationCategories::IN_APP, false);
        $set('branch_a_employee_2', 'digest_weekly', NotificationCategories::EMAIL, false);
        $set('branch_a_employee_2', 'digest_daily', NotificationCategories::IN_APP, true);
        // Olga reads everything in the system and gets as few letters as possible.
        $set('branch_b_employee_1', 'tasks', NotificationCategories::EMAIL, false);
        $set('branch_b_employee_1', 'announcements', NotificationCategories::EMAIL, false);
        $set('branch_b_employee_1', 'events', NotificationCategories::EMAIL, false);

        // A default of a role, changed by the administrator: operators of the inbox get CRM notices by e-mail too.
        $this->by('super_admin', fn (User $admin) => $preferences->setDefault(
            $admin, Role::query()->where('code', 'inbox_operator')->firstOrFail(), 'crm', NotificationCategories::EMAIL, true,
        ));
    }

    private function announcements(): void
    {
        $announcements = app(Announcements::class);
        $this->by('org_head', fn (User $elena) => $announcements->send($elena, [
            'title' => self::ANNOUNCEMENT,
            'body' => "Sediile lucrează până la ora 14:00 în ajunul sărbătorilor.\nÎntrebări — către șefii de filiale.",
        ]));
        // The head of a branch reaches her own branch only, whatever she asks for.
        $this->at(3, fn () => $this->by('branch_a_head', fn (User $ana) => $announcements->send($ana, [
            'title' => self::BRANCH_ANNOUNCEMENT, 'body' => 'Se schimbă instalația electrică. Lucrăm de acasă, ședința — online.',
        ], Person::query())));
    }

    private function criticalNotice(): void
    {
        $announcements = app(Announcements::class);
        $critical = $this->by('security', fn (User $alexandru): Announcement => $announcements->send($alexandru, [
            'title' => self::CRITICAL, 'critical' => true,
            'body' => 'A fost depistată o tentativă de phishing. Schimbați parola și verificați dacă este activată autentificarea în doi pași.',
        ]));

        $pending = array_map(fn (string $key): int => Personas::user($key)->id, self::NOT_ACKNOWLEDGED);
        $this->at(1, function () use ($announcements, $critical, $pending): void {
            foreach (User::query()->whereKey($critical->receipts()->pluck('user_id'))->whereKeyNot($pending)->get() as $user) {
                $this->as($user, fn () => $announcements->acknowledge($user, $critical));
            }
        });
    }

    /**
     * What is older than four days has been read; the rest waits — so everyone has both read and unread ones.
     */
    private function readOlderNotifications(): void
    {
        $center = app(NotificationCenter::class);
        foreach (User::query()->where('status', UserStatus::Active)->get() as $user) {
            foreach ($user->unreadNotifications()->where('created_at', '<', now()->subDays(4))->pluck('id') as $id) {
                $center->markRead($user, (string) $id);
            }
        }
    }

    private function digests(): void
    {
        Artisan::call('notifications:digest', ['frequency' => 'weekly']);
        Artisan::call('notifications:digest', ['frequency' => 'daily']);
    }
}
