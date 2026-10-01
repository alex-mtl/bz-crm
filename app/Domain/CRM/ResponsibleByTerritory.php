<?php

declare(strict_types=1);

namespace App\Domain\CRM;

use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Models\TerritoryResponsible;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;

/**
 * Who answers for a place (Д-22): the responsible of the territory itself, or of the nearest territory above it.
 * Only a person with an active account can take a lead or an appeal (Д-15), others are skipped.
 */
final class ResponsibleByTerritory
{
    public function find(?int $territoryId): ?int
    {
        $territory = $territoryId !== null ? Territory::query()->find($territoryId) : null;
        if ($territory === null) {
            return null;
        }

        // From the territory itself upwards: the nearest level that has a responsible wins.
        foreach ([$territory->id, ...array_reverse($territory->ancestorIds())] as $id) {
            $personIds = TerritoryResponsible::query()->where('territory_id', $id)->orderBy('assigned_at')->orderBy('id')->pluck('person_id');
            foreach ($personIds as $personId) {
                if (User::query()->where('person_id', $personId)->where('status', UserStatus::Active)->exists()) {
                    return (int) $personId;
                }
            }
        }

        return null;
    }
}
