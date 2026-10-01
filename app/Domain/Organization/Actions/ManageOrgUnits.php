<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Exceptions\OrganizationRuleViolation;
use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\Organization\OrgStructure;
use Illuminate\Support\Facades\DB;

/**
 * The unit tree (ФО §7, ТЗ §11): create, rename, move, archive, bind to territories (Д-3).
 * An unsaved OrgUnit as the subject means "outside any unit" — only organization-wide scopes cover it.
 */
final readonly class ManageOrgUnits
{
    public function __construct(
        private AuthorizationService $authorization,
        private OrgStructure $structure,
        private EventJournal $journal,
    ) {}

    public function create(User $actor, string $name, ?OrgUnit $parent = null, ?string $description = null): OrgUnit
    {
        $this->authorization->authorize($actor, 'org_units.manage', $parent ?? new OrgUnit);

        return DB::transaction(function () use ($name, $parent, $description): OrgUnit {
            $unit = OrgUnit::query()->create([
                'parent_id' => $parent?->id,
                'name' => trim($name),
                'description' => $description,
                'depth' => $parent !== null ? $parent->depth + 1 : 0,
                'sort_order' => (int) OrgUnit::query()->where('parent_id', $parent?->id)->max('sort_order') + 10,
            ]);
            $unit->forceFill(['path' => ($parent !== null ? $parent->path : '/').$unit->id.'/'])->save();
            $this->journal->record('org.unit.created', $unit, [], ['name' => $unit->name, 'parent_id' => $parent?->id]);

            return $unit;
        });
    }

    public function update(User $actor, OrgUnit $unit, string $name, ?string $description): OrgUnit
    {
        $this->authorization->authorize($actor, 'org_units.manage', $unit);

        return DB::transaction(function () use ($unit, $name, $description): OrgUnit {
            $old = $unit->only(['name', 'description']);
            $unit->update(['name' => trim($name), 'description' => $description]);
            if ($unit->wasChanged()) {
                $this->journal->record('org.unit.updated', $unit, $old, $unit->only(['name', 'description']));
            }

            return $unit;
        });
    }

    public function move(User $actor, OrgUnit $unit, ?OrgUnit $newParent): void
    {
        $this->authorization->authorize($actor, 'org_units.manage', $unit);
        $this->authorization->authorize($actor, 'org_units.manage', $newParent ?? new OrgUnit);
        if ($newParent !== null && $unit->covers($newParent)) {
            throw OrganizationRuleViolation::because('move_into_own_subtree');
        }

        DB::transaction(function () use ($unit, $newParent): void {
            $oldParent = $unit->parent_id;
            $oldPath = $unit->path;
            $newPath = ($newParent !== null ? $newParent->path : '/').$unit->id.'/';
            $depthShift = ($newParent !== null ? $newParent->depth + 1 : 0) - $unit->depth;

            foreach (OrgUnit::query()->withinPath($oldPath)->get() as $node) {
                $node->forceFill([
                    'path' => $newPath.substr($node->path, strlen($oldPath)),
                    'depth' => $node->depth + $depthShift,
                ])->save();
            }
            $unit->forceFill(['parent_id' => $newParent?->id])->save();
            $this->journal->record('org.unit.moved', $unit, ['parent_id' => $oldParent], ['parent_id' => $newParent?->id]);
        });
    }

    public function archive(User $actor, OrgUnit $unit): void
    {
        $this->authorization->authorize($actor, 'org_units.manage', $unit);
        if (OrgMembership::query()->where('org_unit_id', $unit->id)->exists()
            || OrgUnit::query()->where('parent_id', $unit->id)->active()->exists()) {
            throw OrganizationRuleViolation::because('archive_not_empty');
        }

        DB::transaction(function () use ($unit): void {
            $unit->update(['archived_at' => now()]);
            $this->journal->record('org.unit.archived', $unit);
        });
    }

    /**
     * Replaces the unit's territories. Inherited access of its members follows at once (Д-3):
     * it is computed from this binding, never copied.
     *
     * @param  list<int>  $territoryIds
     */
    public function setTerritories(User $actor, OrgUnit $unit, array $territoryIds): void
    {
        $this->authorization->authorize($actor, 'org_units.territories.manage', $unit);
        $territoryIds = array_values(array_unique(array_map('intval', $territoryIds)));
        if (Territory::query()->whereKey($territoryIds)->count() !== count($territoryIds)) {
            throw OrganizationRuleViolation::because('unknown_territory');
        }

        DB::transaction(function () use ($actor, $unit, $territoryIds): void {
            $old = $this->structure->unitTerritoryIds($unit->id);
            $unit->territories()->sync(array_fill_keys($territoryIds, ['assigned_by_user_id' => $actor->id, 'assigned_at' => now()]));
            sort($old);
            $new = $territoryIds;
            sort($new);
            if ($old !== $new) {
                $this->journal->record('org.unit.territories_changed', $unit, ['territories' => $old], ['territories' => $new]);
            }
        });

        $this->authorization->forget();
    }
}
