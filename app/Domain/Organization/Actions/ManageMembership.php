<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Exceptions\OrganizationRuleViolation;
use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgMembershipHistory;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Membership and the direct manager (Д-11): exactly one unit per person, the unit head is the default
 * manager, a transfer keeps history, a head change moves the "not re-configured" members to the new head.
 */
final readonly class ManageMembership
{
    public function __construct(
        private AuthorizationService $authorization,
        private OrgStructure $structure,
        private EventJournal $journal,
    ) {}

    public function place(User $actor, Person $person, OrgUnit $unit, ?string $position = null): OrgMembership
    {
        $this->authorization->authorize($actor, 'people.transfer', $unit);
        if (OrgMembership::query()->whereKey($person->id)->exists()) {
            throw OrganizationRuleViolation::because('already_member');
        }

        return DB::transaction(function () use ($person, $unit, $position): OrgMembership {
            $membership = OrgMembership::query()->create([
                'person_id' => $person->id,
                'org_unit_id' => $unit->id,
                'position' => $position,
                'manager_person_id' => $this->structure->defaultManagerFor($unit, $person->id),
                'manager_set_manually' => false,
                'joined_at' => now(),
            ]);
            $this->journal->record('org.membership.created', $person, [], [
                'org_unit_id' => $unit->id, 'position' => $position, 'manager_person_id' => $membership->manager_person_id,
            ]);
            $this->forget();

            return $membership;
        });
    }

    /**
     * Moves the person to another unit (Д-11 п. 5): new default manager, history kept.
     * Inherited territories change with the unit; direct territory grants stay (Д-3).
     */
    public function transfer(User $actor, Person $person, OrgUnit $to, string $reason): OrgMembership
    {
        $membership = $this->membershipOf($person);
        $this->authorization->authorize($actor, 'people.transfer', $person);
        $this->authorization->authorize($actor, 'people.transfer', $to);
        if (trim($reason) === '') {
            throw OrganizationRuleViolation::because('reason_required');
        }
        if ($membership->org_unit_id === $to->id) {
            return $membership;
        }
        if (OrgUnit::query()->where('head_person_id', $person->id)->exists()) {
            throw OrganizationRuleViolation::because('transfer_head');
        }

        return DB::transaction(function () use ($actor, $person, $membership, $to, $reason): OrgMembership {
            $from = $membership->org_unit_id;
            OrgMembershipHistory::query()->create([
                'person_id' => $person->id, 'org_unit_id' => $from, 'position' => $membership->position,
                'joined_at' => $membership->joined_at, 'left_at' => now(), 'left_reason' => 'transfer', 'changed_by_user_id' => $actor->id,
            ]);
            $oldManager = $membership->manager_person_id;
            $membership->update([
                'org_unit_id' => $to->id,
                'manager_person_id' => $this->structure->defaultManagerFor($to, $person->id),
                'manager_set_manually' => false,
                'joined_at' => now(),
            ]);
            $this->journal->record('org.membership.transferred', $person,
                ['org_unit_id' => $from, 'manager_person_id' => $oldManager],
                ['org_unit_id' => $to->id, 'manager_person_id' => $membership->manager_person_id, 'reason' => trim($reason)]);

            // People who reported to them by a manual choice would now have a manager from another unit (Д-11 п. 1).
            foreach (OrgMembership::query()->where('manager_person_id', $person->id)->where('org_unit_id', '!=', $to->id)->get() as $report) {
                $this->setManager($report, $this->structure->defaultManagerFor($report->unit, $report->person_id), false, 'manager_transferred');
            }
            $this->forget();

            return $membership;
        });
    }

    /**
     * The unit head changes a member's direct manager (Д-11 п. 1–2): only someone from the same unit or its children.
     */
    public function changeManager(User $actor, Person $person, Person $newManager): OrgMembership
    {
        $membership = $this->membershipOf($person);
        $this->authorization->authorize($actor, 'people.manager.change', $person);

        $managerMembership = OrgMembership::query()->with('unit')->find($newManager->id);
        if ($newManager->id === $person->id
            || $managerMembership === null
            || ! $membership->unit->covers($managerMembership->unit)) {
            throw OrganizationRuleViolation::because('manager_outside_unit');
        }
        if ($newManager->user === null || ! $newManager->user->isActive()) {
            throw OrganizationRuleViolation::because('manager_not_active');
        }
        if (in_array($newManager->id, $this->structure->subordinates($person->id), true)) {
            throw OrganizationRuleViolation::because('manager_cycle');
        }

        return DB::transaction(function () use ($membership, $newManager): OrgMembership {
            $isDefault = $this->structure->defaultManagerFor($membership->unit, $membership->person_id) === $newManager->id;
            $this->setManager($membership, $newManager->id, ! $isDefault, 'manual');
            $this->forget();

            return $membership;
        });
    }

    /**
     * Sets (or clears) the unit head. Members whose manager was never changed by hand follow the new head;
     * heads of child units get the new head as their manager (Д-11 п. 2–3).
     */
    public function assignHead(User $actor, OrgUnit $unit, ?Person $head): void
    {
        $this->authorization->authorize($actor, 'org_units.assign_head', $unit);
        if ($head !== null) {
            if (OrgMembership::query()->whereKey($head->id)->value('org_unit_id') !== $unit->id) {
                throw OrganizationRuleViolation::because('head_not_member');
            }
            if ($head->user === null || ! $head->user->isActive()) {
                throw OrganizationRuleViolation::because('manager_not_active');
            }
        }

        DB::transaction(function () use ($unit, $head): void {
            $old = $unit->head_person_id;
            $unit->update(['head_person_id' => $head?->id]);
            $this->journal->record('org.unit.head_changed', $unit, ['head_person_id' => $old], ['head_person_id' => $head?->id]);
            $this->forget();

            $subtree = OrgUnit::query()->withinPath($unit->path)->pluck('id');
            $members = OrgMembership::query()->with('unit.parent')->whereIn('org_unit_id', $subtree)->where('manager_set_manually', false)->get();
            foreach ($members as $membership) {
                $default = $this->structure->defaultManagerFor($membership->unit, $membership->person_id);
                if ($default !== $membership->manager_person_id) {
                    $this->setManager($membership, $default, false, 'head_changed');
                }
            }
            $this->forget();
        });

        $this->authorization->forget();
    }

    public function setPosition(User $actor, Person $person, ?string $position): void
    {
        $membership = $this->membershipOf($person);
        $this->authorization->authorize($actor, 'people.update', $person);

        DB::transaction(function () use ($person, $membership, $position): void {
            $old = $membership->position;
            $membership->update(['position' => $position !== null && trim($position) !== '' ? trim($position) : null]);
            if ($membership->wasChanged()) {
                $this->journal->record('org.membership.position_changed', $person, ['position' => $old], ['position' => $membership->position]);
            }
        });
    }

    private function setManager(OrgMembership $membership, ?int $managerId, bool $manual, string $reason): void
    {
        $old = $membership->manager_person_id;
        $membership->update(['manager_person_id' => $managerId, 'manager_set_manually' => $manual]);
        if ($old !== $managerId) {
            $this->journal->record('org.manager.changed', $membership->person,
                ['manager_person_id' => $old], ['manager_person_id' => $managerId, 'reason' => $reason]);
        }
    }

    private function membershipOf(Person $person): OrgMembership
    {
        return OrgMembership::query()->with('unit')->find($person->id)
            ?? throw OrganizationRuleViolation::because('not_member');
    }

    private function forget(): void
    {
        $this->structure->forget();
        $this->authorization->forget();
    }
}
