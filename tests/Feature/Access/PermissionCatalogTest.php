<?php

use App\Domain\Access\Actions\SyncSystemRoles;
use App\Domain\Access\Enums\PermissionEffect;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\RolePermission;
use App\Domain\Access\PermissionRegistry;

it('declares default roles for every non-reserved permission — none is forgotten (ФО §6.2)', function () {
    $undeclared = collect(app(PermissionRegistry::class)->all())
        ->reject(fn ($definition) => $definition->reserved || $definition->defaultRoles !== [])
        ->keys()->all();

    expect($undeclared)->toBe([]);
});

it('gives the super admin every non-reserved permission and applies all declared defaults', function () {
    app(SyncSystemRoles::class)();

    $superAdmin = Role::query()->where('code', 'super_admin')->sole();
    foreach (app(PermissionRegistry::class)->all() as $definition) {
        if ($definition->reserved) {
            continue;
        }
        expect($superAdmin->permissions()->where('permission_code', $definition->code)->exists())->toBeTrue();
        foreach ($definition->defaultRoles as $roleCode) {
            $has = RolePermission::query()->where('permission_code', $definition->code)->where('effect', PermissionEffect::Allow)
                ->whereHas('role', fn ($q) => $q->where('code', $roleCode))->exists();
            expect($has)->toBeTrue();
        }
    }
});

it('does not give reserved permissions to anyone by default (Д-17)', function () {
    app(SyncSystemRoles::class)();

    $reserved = collect(app(PermissionRegistry::class)->all())->filter->reserved->keys()->all();

    expect($reserved)->not->toBeEmpty()
        ->and(RolePermission::query()->whereIn('permission_code', $reserved)->count())->toBe(0);
});

it('has a ro, ru and en label for every permission', function (string $locale) {
    $missing = collect(app(PermissionRegistry::class)->all())
        ->filter(fn ($definition) => trans($definition->labelKey(), [], $locale) === $definition->labelKey())
        ->keys()->all();

    expect($missing)->toBe([]);
})->with(['ro', 'ru', 'en']);

it('creates the starter roles with names in three languages', function () {
    app(SyncSystemRoles::class)();

    expect(Role::query()->where('is_system', true)->count())->toBe(12)
        ->and(Role::query()->where('code', 'catalog_admin')->value('name_ru'))->toBe('Администратор справочников');
});

it('does not bring back a default right that an admin removed', function () {
    app(SyncSystemRoles::class)();
    $role = Role::query()->where('code', 'hr')->sole();
    $role->permissions()->where('permission_code', 'users.approve')->delete();

    $second = app(SyncSystemRoles::class)();

    expect($second['permissions_added'])->toBe(0)
        ->and($role->permissions()->where('permission_code', 'users.approve')->exists())->toBeFalse();
});
