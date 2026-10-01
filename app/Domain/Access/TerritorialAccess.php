<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Models\TerritoryGrant;
use App\Domain\Geo\Models\Territory;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\Organization\OrgStructure;

/**
 * Effective territorial access of a person (Д-3) = territories of their unit (inherited, recomputed on
 * transfer or re-binding — never copied) ∪ their direct grants in effect. Each territory covers its subtree.
 */
final class TerritorialAccess
{
    /** @var array<int, list<array{territory: Territory, source: string, unit: OrgUnit|null, grant: TerritoryGrant|null}>> */
    private array $cache = [];

    public function __construct(private readonly OrgStructure $org) {}

    /**
     * Where each part of the access comes from — for the permission simulator (plan 2b).
     *
     * @return list<array{territory: Territory, source: string, unit: OrgUnit|null, grant: TerritoryGrant|null}>
     */
    public function explain(int $personId): array
    {
        if (isset($this->cache[$personId])) {
            return $this->cache[$personId];
        }

        $result = [];
        $unit = $this->org->unitOf($personId);
        if ($unit !== null) {
            foreach ($unit->territories()->get() as $territory) {
                $result[] = ['territory' => $territory, 'source' => 'unit', 'unit' => $unit, 'grant' => null];
            }
        }
        foreach (TerritoryGrant::query()->with('territory')->where('person_id', $personId)->inEffect()->get() as $grant) {
            $result[] = ['territory' => $grant->territory, 'source' => 'grant', 'unit' => null, 'grant' => $grant];
        }

        return $this->cache[$personId] = $result;
    }

    /**
     * @return list<string> territory paths, each covering its subtree
     */
    public function paths(int $personId): array
    {
        return array_values(array_unique(array_map(fn (array $row): string => $row['territory']->path, $this->explain($personId))));
    }

    public function covers(int $personId, Territory $territory): bool
    {
        foreach ($this->paths($personId) as $path) {
            if ($path !== '' && str_starts_with($territory->path, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Two sets of territory paths overlap when one node lies inside the other's subtree (Д-13 "people of the region").
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function overlap(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x !== '' && $y !== '' && (str_starts_with($x, $y) || str_starts_with($y, $x))) {
                    return true;
                }
            }
        }

        return false;
    }

    public function forget(): void
    {
        $this->cache = [];
    }
}
