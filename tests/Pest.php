<?php

use App\Domain\Access\Actions\SyncSystemRoles;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Scenarios\UsesDemoWorld;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Architecture');

pest()->extend(TestCase::class)
    ->use(UsesDemoWorld::class)
    ->group('scenarios')
    ->in('Scenarios');

/**
 * Test helper: how many journal entries of this type exist.
 */
function journalCount(string $eventType): int
{
    return JournalEntry::query()->where('event_type', $eventType)->count();
}

/**
 * Test helper: an active user holding the given system roles (starter permissions applied).
 */
function userWithRoles(string ...$roleCodes): User
{
    if (Role::query()->doesntExist()) {
        app(SyncSystemRoles::class)();
    }

    $user = User::factory()->create();
    foreach ($roleCodes as $code) {
        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $code)->value('id'),
            'granted_at' => now(),
        ]);
    }
    app(AuthorizationService::class)->forget($user);

    return $user;
}
