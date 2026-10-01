<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Geo\Models\Territory;
use App\Domain\Organization\Models\OrgUnit;
use Filament\Forms\Components\Select;

/**
 * The two "where" pickers used all over the CRM: a territory (searched by any of its names) and an org unit.
 */
final class Places
{
    public static function territory(string $name = 'territory_id'): Select
    {
        return Select::make($name)->label(__('admin.territories.singular'))->searchable()
            ->getSearchResultsUsing(fn (string $search): array => Territory::query()->search($search)->limit(30)->get()
                ->mapWithKeys(fn (Territory $t): array => [$t->id => $t->name()])->all())
            ->getOptionLabelUsing(fn ($value): ?string => self::territoryName($value));
    }

    public static function unit(string $name = 'org_unit_id'): Select
    {
        return Select::make($name)->label(__('admin.org_units.singular'))->options(fn (): array => self::units());
    }

    /**
     * @return array<int, string>
     */
    public static function units(): array
    {
        return OrgUnit::query()->active()->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u): array => [$u->id => str_repeat('— ', $u->depth).$u->name])->all();
    }

    public static function territoryName(mixed $id): ?string
    {
        return $id !== null ? Territory::query()->find($id)?->name() : null;
    }

    public static function unitName(mixed $id): ?string
    {
        return $id !== null ? OrgUnit::query()->whereKey($id)->value('name') : null;
    }
}
