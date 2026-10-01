<?php

use App\Domain\Access\Models\Role;
use App\Domain\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;

it('refuses to seed the demo world in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => DemoSeeder::ensureAllowed())->toThrow(RuntimeException::class);
});

it('refuses to seed the demo world without the shared demo password', function () {
    config(['demo.password' => '']);

    expect(fn () => DemoSeeder::ensureAllowed())->toThrow(RuntimeException::class, 'DEMO_USER_PASSWORD');
});

it('loads only reference data when the whole database seeder runs in production', function () {
    app()->detectEnvironment(fn () => 'production');

    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect(User::query()->where('email', 'like', '%@'.config('demo.email_domain'))->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(0)
        ->and(Role::query()->count())->toBeGreaterThan(0);
});
