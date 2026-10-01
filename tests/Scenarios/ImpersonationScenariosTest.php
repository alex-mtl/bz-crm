<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Enums\ActingAs;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Models\Impersonation;
use App\Domain\Identity\Models\User;
use Database\Seeders\Demo\Personas;

/*
 * Д-19 — impersonation on the demo world (docs/demo/README.md § Имперсонация).
 */

it('shows the super admin\'s 12-minute session as Ion: reason, journal, notice to Ion', function () {
    $session = Impersonation::query()->sole();
    $ion = Personas::user('branch_a_employee_1');
    $ended = JournalEntry::query()->where('event_type', 'identity.impersonation.ended')->sole();

    expect($session->impersonator_user_id)->toBe(Personas::user('super_admin')->id)
        ->and($session->target_user_id)->toBe($ion->id)
        ->and($session->reason)->not->toBeEmpty()
        ->and($session->end_reason)->toBe(Impersonation::END_STOPPED)
        ->and((int) $session->started_at->diffInMinutes($session->ended_at))->toBe(12)
        ->and(JournalEntry::query()->where('event_type', 'identity.impersonation.started')->sole()->subject_id)->toBe((string) $ion->id)
        ->and($ended->acting_as)->toBe(ActingAs::Impersonation)
        ->and($ended->actor_user_id)->toBe(Personas::user('super_admin')->id)
        ->and($ion->notifications()->where('data->kind', 'impersonation')->exists())->toBeTrue();
});

it('gives the impersonation right to the super admin only', function () {
    $holders = User::query()->get()->filter(fn (User $user): bool => app(AuthorizationService::class)->can($user, 'users.impersonate'));
    $superAdmins = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
        ->where('roles.code', 'super_admin')->pluck('user_roles.user_id')->all();

    expect($holders->pluck('id')->all())->toEqualCanonicalizing($superAdmins)
        ->toContain(Personas::user('super_admin')->id)
        ->not->toContain(Personas::user('org_head')->id, Personas::user('security')->id);
});
