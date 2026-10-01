<?php

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\CreateRole;
use App\Domain\Access\Actions\RevokeRole;
use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Exceptions\PermissionEscalation;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\Models\UserRole;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Illuminate\Auth\Access\AuthorizationException;

function authz(): AuthorizationService
{
    return app(AuthorizationService::class);
}

it('allows a code granted by a role in effect', function () {
    $hr = userWithRoles('hr');

    expect(authz()->can($hr, 'users.approve'))->toBeTrue()
        ->and(authz()->can($hr, 'roles.manage'))->toBeFalse();
});

it('lets an explicit deny in one role override an allow in another', function () {
    $user = userWithRoles('hr');
    $blocker = Role::query()->create(['code' => 'no_approvals', 'name_ro' => 'x', 'name_ru' => 'x', 'name_en' => 'x']);
    RolePermission::query()->create(['role_id' => $blocker->id, 'permission_code' => 'users.approve', 'effect' => PermissionEffect::Deny]);
    UserRole::query()->create(['user_id' => $user->id, 'role_id' => $blocker->id, 'granted_at' => now()]);
    authz()->forget($user);

    expect(authz()->can($user, 'users.approve'))->toBeFalse()
        ->and(authz()->can($user, 'users.invite'))->toBeTrue();
});

it('denies everything to accounts that are not active, whatever their roles', function () {
    $user = userWithRoles('super_admin');
    $user->update(['status' => 'deactivated']);

    expect(authz()->can($user->fresh(), 'roles.manage'))->toBeFalse();
});

it('ignores expired role assignments', function () {
    $user = userWithRoles();
    UserRole::query()->create([
        'user_id' => $user->id, 'role_id' => Role::query()->where('code', 'hr')->value('id'),
        'granted_at' => now()->subMonth(), 'expires_at' => now()->subDay(),
    ]);
    authz()->forget($user);

    expect(authz()->can($user, 'users.approve'))->toBeFalse();
});

it('denies unknown permission codes', function () {
    expect(authz()->can(userWithRoles('super_admin'), 'nobody.knows.this'))->toBeFalse();
});

it('applies the data layer: own profile only', function () {
    $user = userWithRoles('employee');
    $someoneElse = Person::factory()->create();

    expect(authz()->can($user, 'profile.own.update', $user->person))->toBeTrue()
        ->and(authz()->can($user, 'profile.own.update', $someoneElse))->toBeFalse()
        ->and($user->can('profile.own.update', $someoneElse))->toBeFalse();
});

it('routes Laravel gate checks through the service', function () {
    expect(userWithRoles('security')->can('users.sessions.terminate'))->toBeTrue()
        ->and(userWithRoles('employee')->can('users.sessions.terminate'))->toBeFalse();
});

it('filters list queries in SQL when the code is missing', function () {
    User::factory()->count(3)->create();

    expect(authz()->scopeQuery(userWithRoles('employee'), 'users.read', User::query())->count())->toBe(0)
        ->and(authz()->scopeQuery(userWithRoles('hr'), 'users.read', User::query())->count())->toBeGreaterThan(3);
});

it('assigns a role within the granter rights and journals it', function () {
    $head = userWithRoles('unit_head');
    $target = User::factory()->create();

    app(AssignRole::class)($head, $target, Role::query()->where('code', 'employee')->sole());

    expect(authz()->can($target, 'profile.own.update'))->toBeTrue()
        ->and(JournalEntry::query()->where('event_type', 'access.role.assigned')->where('subject_id', $target->id)->exists())->toBeTrue();
});

it('refuses to grant rights the granter does not have and journals the attempt (Д-17)', function () {
    $head = userWithRoles('unit_head');
    $target = User::factory()->create();

    expect(fn () => app(AssignRole::class)($head, $target, Role::query()->where('code', 'super_admin')->sole()))
        ->toThrow(PermissionEscalation::class);

    $denial = JournalEntry::query()->where('event_type', 'access.escalation.denied')->sole();
    expect($denial->new_values['role'])->toBe('super_admin')
        ->and($denial->new_values['missing'])->toContain('roles.manage')
        ->and(authz()->can($target, 'roles.manage'))->toBeFalse();
});

it('requires roles.assign to assign roles at all', function () {
    app(AssignRole::class)(userWithRoles('employee'), User::factory()->create(), Role::query()->where('code', 'employee')->sole());
})->throws(AuthorizationException::class);

it('revokes only what the revoker could have granted', function () {
    $admin = userWithRoles('super_admin');
    $target = userWithRoles('hr');
    $assignment = UserRole::query()->where('user_id', $target->id)->sole();

    expect(fn () => app(RevokeRole::class)(userWithRoles('unit_head'), $assignment))->toThrow(PermissionEscalation::class);

    app(RevokeRole::class)($admin, $assignment);
    expect(authz()->can($target, 'users.approve'))->toBeFalse();
});

it('journals a reserved permission grant separately as a security event', function () {
    $admin = userWithRoles('super_admin');
    $role = app(CreateRole::class)($admin, 'auditors', ['ru' => 'Аудиторы'], 'ru');

    app(SetRolePermissions::class)($admin, $role, ['audit.read', 'profile.layers.export']);

    $event = JournalEntry::query()->where('event_type', 'access.reserved_permission.granted')->sole();
    expect($event->new_values)->toBe(['permission' => 'profile.layers.export'])
        ->and($event->category->value)->toBe('security');
});

it('copies a role name to missing languages and flags them as unverified (Д-16)', function () {
    $role = app(CreateRole::class)(userWithRoles('super_admin'), 'field_team', ['ru' => 'Полевая команда'], 'ru');

    expect($role->name_ro)->toBe('Полевая команда')
        ->and($role->name_en)->toBe('Полевая команда')
        ->and($role->unverified_locales)->toBe(['ro', 'en']);
});
