<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\GrantInitialSuperAdmin;
use App\Domain\Access\Actions\SyncSystemRoles;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Organization\Actions\ManageOrgUnits;
use App\Domain\Organization\Models\OrgUnit;

/**
 * A small organization for feature tests (the full one lives in the demo world):
 *
 *   Moldova ─ Chișinău ─┬─ Centru
 *                       └─ Botanica
 *           └ Bălți
 *
 *   Root (head: orgHead, territory Moldova)
 *   ├─ Regional Chișinău (head: regionHead, territory Chișinău)
 *   │   ├─ Branch A (head: headA, territory Centru): a1, a2
 *   │   └─ Branch B (head: headB, territory Botanica): b1
 *   ├─ Bălți (head: baltiHead, territory Bălți): balti1
 *   └─ Central office (no territories): central1
 */
final class OrgFixture
{
    public User $admin;

    public User $orgHead;

    public User $regionHead;

    public User $headA;

    public User $headB;

    public User $baltiHead;

    public User $a1;

    public User $a2;

    public User $b1;

    public User $balti1;

    public User $central1;

    public OrgUnit $root;

    public OrgUnit $regional;

    public OrgUnit $branchA;

    public OrgUnit $branchB;

    public OrgUnit $balti;

    public OrgUnit $central;

    public Territory $moldova;

    public Territory $chisinau;

    public Territory $centru;

    public Territory $botanica;

    public Territory $baltiTerritory;

    public static function build(): self
    {
        app(SyncSystemRoles::class)();
        $f = new self;
        $f->admin = User::factory()->create();
        app(GrantInitialSuperAdmin::class)($f->admin);

        $f->moldova = self::territory('md', null, Territory::COUNTRY);
        $f->chisinau = self::territory('chisinau', $f->moldova, Territory::DISTRICT);
        $f->centru = self::territory('chisinau/centru', $f->chisinau, Territory::SECTOR);
        $f->botanica = self::territory('chisinau/botanica', $f->chisinau, Territory::SECTOR);
        $f->baltiTerritory = self::territory('balti', $f->moldova, Territory::DISTRICT);

        $units = app(ManageOrgUnits::class);
        $f->root = $units->create($f->admin, 'Organizația');
        $f->regional = $units->create($f->admin, 'Organizația regională Chișinău', $f->root);
        $f->branchA = $units->create($f->admin, 'Filiala A', $f->regional);
        $f->branchB = $units->create($f->admin, 'Filiala B', $f->regional);
        $f->balti = $units->create($f->admin, 'Organizația Bălți', $f->root);
        $f->central = $units->create($f->admin, 'Aparatul central', $f->root);
        $units->setTerritories($f->admin, $f->root, [$f->moldova->id]);
        $units->setTerritories($f->admin, $f->regional, [$f->chisinau->id]);
        $units->setTerritories($f->admin, $f->branchA, [$f->centru->id]);
        $units->setTerritories($f->admin, $f->branchB, [$f->botanica->id]);
        $units->setTerritories($f->admin, $f->balti, [$f->baltiTerritory->id]);

        $f->orgHead = $f->member($f->root, ['org_head' => ScopeType::Organization], head: true);
        $f->regionHead = $f->member($f->regional, ['unit_head' => ScopeType::OwnUnit], head: true);
        $f->headA = $f->member($f->branchA, ['unit_head' => ScopeType::OwnUnit], head: true);
        $f->headB = $f->member($f->branchB, ['unit_head' => ScopeType::OwnUnit], head: true);
        $f->baltiHead = $f->member($f->balti, ['unit_head' => ScopeType::OwnUnit], head: true);
        $f->a1 = $f->member($f->branchA);
        $f->a2 = $f->member($f->branchA);
        $f->b1 = $f->member($f->branchB);
        $f->balti1 = $f->member($f->balti);
        $f->central1 = $f->member($f->central);

        app(AuthorizationService::class)->forget();

        return $f;
    }

    public static function territory(string $code, ?Territory $parent, string $level): Territory
    {
        $territory = Territory::query()->create([
            'parent_id' => $parent?->id, 'level' => $level, 'code' => $code,
            'name_ro' => $code, 'name_ru' => $code, 'name_en' => $code,
            'depth' => $parent !== null ? $parent->depth + 1 : 0,
        ]);
        $territory->forceFill(['path' => ($parent?->path ?? '/').$territory->id.'/'])->save();

        return $territory;
    }

    /**
     * A member of the unit; everyone is also an employee organization-wide (the starter setup).
     *
     * @param  array<string, ScopeType>  $roles
     */
    public function member(OrgUnit $unit, array $roles = [], bool $head = false): User
    {
        $user = User::factory()->create();
        app(ManageMembership::class)->place($this->admin, $user->person, $unit);
        foreach (['employee' => ScopeType::Organization, ...$roles] as $code => $scope) {
            app(AssignRole::class)($this->admin, $user, Role::query()->where('code', $code)->sole(), scope: $scope);
        }
        if ($head) {
            app(ManageMembership::class)->assignHead($this->admin, $unit->fresh(), $user->person);
        }
        app(AuthorizationService::class)->forget();

        return $user->fresh();
    }
}
