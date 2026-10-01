<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Models\TerritoryResponsible;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Support\Translation\TranslatedNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editing the territory reference (ФО §6.11, Д-7): content is editable and depth can be added —
 * e.g. polling stations under a locality. Official names come from the import (Д-9).
 */
final readonly class ManageTerritories
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array<string, string|null>  $names
     * @param  list<string>  $aliases
     */
    public function add(User $actor, Territory $parent, string $level, array $names, string $sourceLocale, array $aliases = []): Territory
    {
        $this->authorization->authorize($actor, 'territories.manage', $parent);
        $translated = TranslatedNames::complete($names, $sourceLocale);

        return DB::transaction(function () use ($parent, $level, $translated, $aliases): Territory {
            $territory = Territory::query()->create([
                'parent_id' => $parent->id,
                'level' => $level,
                'code' => $parent->code.'/'.Str::slug($translated->names['ro']).'-'.Str::lower(Str::random(4)),
                'name_ro' => $translated->names['ro'],
                'name_ru' => $translated->names['ru'],
                'name_en' => $translated->names['en'],
                'search_aliases' => $aliases === [] ? null : $aliases,
                'depth' => $parent->depth + 1,
                'sort_order' => (int) Territory::query()->where('parent_id', $parent->id)->max('sort_order') + 10,
            ]);
            $territory->forceFill(['path' => $parent->path.$territory->id.'/'])->save();
            $this->journal->record('geo.territory.created', $territory, [], $territory->only(['code', 'level', 'name_ro', 'name_ru', 'name_en']));

            return $territory;
        });
    }

    /**
     * @param  list<string>  $aliases
     */
    public function update(User $actor, Territory $territory, ?string $description, array $aliases, bool $active): Territory
    {
        $this->authorization->authorize($actor, 'territories.manage', $territory);

        return DB::transaction(function () use ($territory, $description, $aliases, $active): Territory {
            $old = $territory->only(['description', 'search_aliases', 'is_active']);
            $territory->update([
                'description' => $description,
                'search_aliases' => $aliases === [] ? null : $aliases,
                'is_active' => $active,
            ]);
            if ($territory->wasChanged()) {
                $this->journal->record('geo.territory.updated', $territory, $old, $territory->only(['description', 'search_aliases', 'is_active']));
            }

            return $territory;
        });
    }

    public function assignResponsible(User $actor, Territory $territory, Person $person): void
    {
        $this->authorization->authorize($actor, 'territories.responsible.assign', $territory);

        DB::transaction(function () use ($actor, $territory, $person): void {
            $row = TerritoryResponsible::query()->firstOrCreate(
                ['territory_id' => $territory->id, 'person_id' => $person->id],
                ['assigned_by_user_id' => $actor->id, 'assigned_at' => now()],
            );
            if ($row->wasRecentlyCreated) {
                $this->journal->record('geo.territory.responsible_assigned', $territory, [], ['person_id' => $person->id]);
            }
        });
    }

    public function removeResponsible(User $actor, TerritoryResponsible $responsible): void
    {
        $this->authorization->authorize($actor, 'territories.responsible.assign', $responsible->territory);

        DB::transaction(function () use ($responsible): void {
            $responsible->delete();
            $this->journal->record('geo.territory.responsible_removed', $responsible->territory, ['person_id' => $responsible->person_id]);
        });
    }
}
