<?php

namespace Database\Seeders;

use App\Domain\Audit\JournalContext;
use App\Domain\Identity\Models\User;
use Database\Seeders\Demo\CrmDemoSeeder;
use Database\Seeders\Demo\EventsDemoSeeder;
use Database\Seeders\Demo\FieldDemoSeeder;
use Database\Seeders\Demo\IdentityDemoSeeder;
use Database\Seeders\Demo\MessagingDemoSeeder;
use Database\Seeders\Demo\NotificationsDemoSeeder;
use Database\Seeders\Demo\OrganizationDemoSeeder;
use Database\Seeders\Demo\Personas;
use Database\Seeders\Demo\ProfilesDemoSeeder;
use Database\Seeders\Demo\SocialDemoSeeder;
use Database\Seeders\Demo\WorkDemoSeeder;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The fictional demo world (Д-2). Grows phase by phase with the same cast; never runs in production.
 * Everything is created through domain actions, so the journal holds the world's real history.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        self::ensureAllowed();

        // The world is built once, on a fresh database (migrate:fresh --seed); a second run would duplicate history.
        if (User::query()->where('email', Personas::emailOf('super_admin'))->exists()) {
            return;
        }

        app(JournalContext::class)->asSystem('seeder:demo')->startCorrelation();

        // The history is built in order and at its own dates: queued work runs at once, inside the step that
        // caused it (so a notification is dated by the demo clock, not by the moment a worker got to it), and
        // no letter leaves the application — the addresses of the demo world do not exist.
        $before = [config('queue.default'), config('mail.default')];
        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        try {
            $this->call([
                IdentityDemoSeeder::class,
                OrganizationDemoSeeder::class,
                ProfilesDemoSeeder::class,
                WorkDemoSeeder::class,
                CrmDemoSeeder::class,
                SocialDemoSeeder::class,
                EventsDemoSeeder::class,
                MessagingDemoSeeder::class,
                FieldDemoSeeder::class,
                NotificationsDemoSeeder::class,
            ]);
        } finally {
            config(['queue.default' => $before[0], 'mail.default' => $before[1]]);
        }
    }

    public static function ensureAllowed(): void
    {
        if (app()->isProduction() || ! app()->environment((array) config('demo.environments'))) {
            throw new RuntimeException('The demo world must never be seeded in this environment ['.app()->environment().'].');
        }
        if (blank(config('demo.password'))) {
            throw new RuntimeException('Set DEMO_USER_PASSWORD in .env before seeding the demo world.');
        }
    }
}
