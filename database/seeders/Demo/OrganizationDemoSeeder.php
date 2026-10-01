<?php

namespace Database\Seeders\Demo;

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\ManageTerritoryGrants;
use App\Domain\Access\Actions\RevokeRole;
use App\Domain\Access\Exceptions\AccessRuleViolation;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\TerritoryGrant;
use App\Domain\Access\Models\UserRole;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Organization\Actions\ManageOrgUnits;
use App\Domain\Organization\Models\OrgUnit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Phase 2 of the demo world (IMPLEMENTATION-PLAN 2e): units and their territories, the cast placed into units,
 * heads and direct managers, scoped roles, delegations, territory grants in every state, a transfer.
 */
class OrganizationDemoSeeder extends Seeder
{
    use DemoSteps;

    public function run(): void
    {
        try {
            $admin = Personas::user('super_admin');
            $this->at(87, fn () => $this->as($admin, fn () => $this->buildStructure($admin)));
            $this->at(87, fn () => $this->as($admin, fn () => $this->scopeRoles($admin)));

            // Pavel went on leave two months ago: Dan acted for two weeks (long expired).
            $pavel = Personas::user('branch_b_head');
            $this->at(60, fn () => $this->as($pavel, fn () => $this->delegateBranchB($pavel, 'branch_b_employee_2', 14, 'Concediu de odihnă')));
            $this->at(45, fn () => Artisan::call('access:expire'));

            // Territory grants (Д-3): expired, revoked, active; and a refused attempt.
            $mihai = Personas::user('chisinau_head');
            $this->at(40, fn () => $this->as($mihai, fn () => $this->grant($mihai, 'branch_b_employee_1', 'chisinau/sectorul-centru', 'Ajutor la colectarea semnăturilor', 20)));
            $this->at(30, fn () => $this->as($mihai, fn () => $this->grant($mihai, 'branch_a_employee_1', 'chisinau/sectorul-buiucani', 'Campanie de informare', null)));
            $this->at(19, fn () => Artisan::call('access:expire'));
            $this->at(10, fn () => $this->as($mihai, fn () => app(ManageTerritoryGrants::class)->revoke($mihai,
                TerritoryGrant::query()->where('person_id', Personas::user('branch_a_employee_1')->person_id)->whereNull('ended_at')->firstOrFail(),
                'Campania s-a încheiat')));
            $this->at(8, fn () => $this->as($mihai, fn () => $this->grant($mihai, 'branch_a_employee_3', 'chisinau/sectorul-botanica', 'Susținere pentru filiala B în perioada alegerilor', 20)));
            $ana = Personas::user('branch_a_head');
            $this->at(7, fn () => $this->as($ana, function () use ($ana): void {
                try {
                    $this->grant($ana, 'branch_a_employee_2', 'chisinau/sectorul-botanica', 'Vreau să ajute filiala B', 10);
                } catch (AccessRuleViolation) {
                    // Expected: a branch head's own access does not cover sector Botanica (Д-3 п. 1); journaled.
                }
            }));

            // Tatiana moves from branch B to Bălți; Ana makes Maria the manager of Ion (Д-11).
            $hr = Personas::user('hr');
            $this->at(40, fn () => $this->as($hr, fn () => app(ManageMembership::class)->transfer($hr, Personas::user('balti_employee_1')->person,
                Personas::unit('balti'), 'Mutare cu domiciliul la Bălți')));
            $this->at(50, fn () => $this->as($ana, fn () => app(ManageMembership::class)->changeManager($ana,
                Personas::user('branch_a_employee_1')->person, Personas::user('branch_a_employee_2')->person)));

            // Pavel is on leave again: Veronica acts as branch head for a few more days.
            $this->at(3, fn () => $this->as($pavel, fn () => $this->delegateBranchB($pavel, 'branch_b_acting_head', 8, 'Concediu medical')));
        } finally {
            $this->resetClock();
        }
    }

    private function buildStructure(User $admin): void
    {
        $units = app(ManageOrgUnits::class);
        $created = [];
        foreach (Personas::UNITS as $key => [$name, $parent, $territories]) {
            $created[$key] = $units->create($admin, $name, $parent !== null ? $created[$parent] : null);
            if ($territories !== []) {
                $units->setTerritories($admin, $created[$key], array_map(fn (string $code): int => Personas::territory($code)->id, $territories));
            }
        }

        $membership = app(ManageMembership::class);
        foreach (Personas::PLACEMENT as $key => $place) {
            $membership->place($admin, Personas::user($key)->person, $created[$place['unit']]->fresh() ?? $created[$place['unit']], $place['position']);
            if ($place['head'] ?? false) {
                $membership->assignHead($admin, OrgUnit::query()->findOrFail($created[$place['unit']]->id), Personas::user($key)->person);
            }
        }
        // The namesake Ion Botnaru (approved volunteer) lives in Bălți.
        $namesake = User::query()->where('email', Personas::email('Ion', 'Botnaru', '.balti'))->firstOrFail();
        $membership->place($admin, $namesake->person, $created['balti']->fresh() ?? $created['balti'], 'Voluntar');
    }

    /**
     * Roles now get scopes (plan 2e): the region head — the Chișinău territory; branch heads — their units.
     * Heads and staff are also employees organization-wide (ADR-008).
     */
    private function scopeRoles(User $admin): void
    {
        $unitHead = Role::query()->where('code', 'unit_head')->firstOrFail();
        $employee = Role::query()->where('code', 'employee')->firstOrFail();
        $assign = app(AssignRole::class);

        $scopes = [
            'chisinau_head' => [ScopeType::Territory, Personas::territory('chisinau')->id],
            'branch_a_head' => [ScopeType::OrgUnit, Personas::unit('branch_a')->id],
            'branch_b_head' => [ScopeType::OrgUnit, Personas::unit('branch_b')->id],
            'balti_head' => [ScopeType::OrgUnit, Personas::unit('balti')->id],
        ];
        foreach ($scopes as $key => [$scope, $id]) {
            $user = Personas::user($key);
            foreach (UserRole::query()->where('user_id', $user->id)->where('role_id', $unitHead->id)->whereNull('scope_type')->get() as $orgWide) {
                app(RevokeRole::class)($admin, $orgWide);
            }
            $assign($admin, $user, $unitHead, scope: $scope, scopeId: $id);
        }

        foreach (['org_head', 'chisinau_head', 'branch_a_head', 'branch_b_head', 'balti_head', 'hr', 'security', 'psychologist', 'catalog_admin', 'inbox_operator', 'moderator'] as $key) {
            $assign($admin, Personas::user($key), $employee);
        }
    }

    private function delegateBranchB(User $head, string $deputyKey, int $days, string $reason): void
    {
        app(AssignRole::class)->delegate($head, Personas::user($deputyKey), Role::query()->where('code', 'unit_head')->firstOrFail(),
            now()->addDays($days), $reason, ScopeType::OrgUnit, Personas::unit('branch_b')->id);
    }

    private function grant(User $by, string $personaKey, string $territoryCode, string $reason, ?int $days): void
    {
        app(TerritorialAccess::class)->forget();
        app(ManageTerritoryGrants::class)->grant($by, Personas::user($personaKey)->person, Personas::territory($territoryCode), $reason,
            $days !== null ? now()->addDays($days) : null);
    }
}
