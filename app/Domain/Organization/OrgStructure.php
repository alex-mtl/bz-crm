<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgUnit;

/**
 * Read-only questions about the organization (Д-11, Д-12). Access and other modules ask here
 * instead of reading the tables themselves.
 */
final class OrgStructure
{
    /** @var array<int, OrgMembership|null> */
    private array $memberships = [];

    public function membership(int $personId): ?OrgMembership
    {
        if (! array_key_exists($personId, $this->memberships)) {
            $this->memberships[$personId] = OrgMembership::query()->with('unit')->find($personId);
        }

        return $this->memberships[$personId];
    }

    public function unitOf(int $personId): ?OrgUnit
    {
        return $this->membership($personId)?->unit;
    }

    public function forget(): void
    {
        $this->memberships = [];
    }

    /**
     * The unit and all its descendants.
     *
     * @return list<int>
     */
    public function subtreeIds(OrgUnit $unit): array
    {
        return OrgUnit::query()->withinPath($unit->path)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Direct manager, their manager, … up to the top (Д-11: "руководство" without the org head).
     *
     * @return list<int> person ids, nearest first
     */
    public function managerChain(int $personId): array
    {
        $chain = [];
        $current = $this->membership($personId)?->manager_person_id;
        while ($current !== null && ! in_array($current, $chain, true) && $current !== $personId) {
            $chain[] = $current;
            $current = $this->membership($current)?->manager_person_id;
        }

        return $chain;
    }

    /**
     * "Руководство" (Д-13): the whole manager chain plus the organization head(s).
     *
     * @return list<int>
     */
    public function management(int $personId): array
    {
        return array_values(array_unique([...$this->managerChain($personId), ...$this->organizationHeadIds()]));
    }

    /**
     * "Подчинённые" (Д-11 п. 4): the whole reporting chain downwards, not just direct reports.
     *
     * @return list<int>
     */
    public function subordinates(int $personId): array
    {
        $result = [];
        $frontier = [$personId];
        while ($frontier !== []) {
            $next = OrgMembership::query()->whereIn('manager_person_id', $frontier)->pluck('person_id')
                ->map(fn ($id): int => (int) $id)
                ->reject(fn (int $id): bool => $id === $personId || in_array($id, $result, true))
                ->values()->all();
            $result = [...$result, ...$next];
            $frontier = $next;
        }

        return $result;
    }

    public function isDirectManager(int $managerPersonId, int $personId): bool
    {
        return $this->membership($personId)?->manager_person_id === $managerPersonId;
    }

    /**
     * Heads of root units: "руководитель организации".
     *
     * @return list<int>
     */
    public function organizationHeadIds(): array
    {
        return OrgUnit::query()->whereNull('parent_id')->whereNotNull('head_person_id')->active()
            ->pluck('head_person_id')->map(fn ($id): int => (int) $id)->all();
    }

    public function isOrganizationHead(int $personId): bool
    {
        return in_array($personId, $this->organizationHeadIds(), true);
    }

    /**
     * Colleagues (Д-12): members of the same unit, the head included. People without a unit have none.
     */
    public function areColleagues(int $personA, int $personB): bool
    {
        $a = $this->membership($personA)?->org_unit_id;

        return $a !== null && $a === $this->membership($personB)?->org_unit_id;
    }

    /**
     * Default direct manager for a member of $unit (Д-11): the unit head; for the head themselves —
     * the head of the nearest ancestor unit that has one.
     */
    public function defaultManagerFor(OrgUnit $unit, int $personId): ?int
    {
        // Read from the database: the unit object at hand may predate a head change.
        $ids = array_reverse(array_map('intval', array_values(array_filter(explode('/', (string) OrgUnit::query()->whereKey($unit->id)->value('path'))))));
        $heads = OrgUnit::query()->whereKey($ids)->pluck('head_person_id', 'id');
        foreach ($ids as $id) {
            $head = $heads[$id] ?? null;
            if ($head !== null && (int) $head !== $personId) {
                return (int) $head;
            }
        }

        return null;
    }

    /**
     * Territories the unit is bound to (Д-3) — the owner's "region" for field visibility (Д-13).
     *
     * @return list<int>
     */
    public function unitTerritoryIds(int $unitId): array
    {
        return OrgUnit::query()->findOrFail($unitId)->territories()->pluck('territories.id')->map(fn ($id): int => (int) $id)->all();
    }
}
