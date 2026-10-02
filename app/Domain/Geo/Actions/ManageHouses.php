<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Models\Appeal;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\Models\Apartment;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Houses and their flats (ФО §6.11). A house stands at one address of the directory and in one territory —
 * the territory decides who manages it. A private house has a single household, so visits work the same way.
 */
final readonly class ManageHouses
{
    public const int MAX_APARTMENTS = 2000;

    public function __construct(
        private FieldAccess $access,
        private AuthorizationService $authorization,
        private ManageAddresses $addresses,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{territory_id: int, street: string, number: string, type_code?: string, entrances?: int|null,
     *               floors?: int|null, residents_count?: int|null, latitude?: float|string|null, longitude?: float|string|null,
     *               description?: string|null, apartments?: int|null}  $data
     */
    public function create(User $actor, array $data): House
    {
        $territory = Territory::query()->findOrFail($data['territory_id']);
        $draft = new House([
            'territory_id' => $territory->id,
            'type_code' => $data['type_code'] ?? House::APARTMENT_BUILDING,
            ...$this->details($data),
            'created_by_person_id' => $actor->person_id,
        ]);
        $this->access->authorize($actor, 'geo.houses.manage', $draft);
        $type = $this->type($draft->type_code);

        return DB::transaction(function () use ($draft, $territory, $data, $type): House {
            // A street belongs to the settlement, not to the polling district the house is counted in.
            $address = $this->addresses->address($this->addresses->street($this->settlement($territory), $data['street']), $data['number']);
            if (House::query()->where('address_id', $address->id)->exists()) {
                throw GeoRuleViolation::because('address_taken');
            }
            $draft->address_id = $address->id;
            $draft->save();

            $count = (bool) $type->property('multi_unit') ? (int) ($data['apartments'] ?? 0) : 1;
            if ($count > 0) {
                $this->generate($draft, 1, $count);
            }
            $this->journal->record('geo.house.created', $draft, [], [
                'address' => $draft->label(), 'territory_id' => $draft->territory_id, 'type_code' => $draft->type_code, 'apartments' => $count,
            ]);

            return $draft;
        });
    }

    /**
     * @param  array<string, mixed>  $data  entrances, floors, residents_count, latitude, longitude, description, territory_id
     */
    public function update(User $actor, House $house, array $data): House
    {
        $this->access->authorize($actor, 'geo.houses.manage', $house);
        $changes = $this->details($data);
        if (isset($data['territory_id']) && (int) $data['territory_id'] !== $house->territory_id) {
            // Moving a house to another territory: the actor must manage houses there as well.
            $this->access->authorize($actor, 'geo.houses.manage', new House(['territory_id' => (int) $data['territory_id']]));
            $changes['territory_id'] = (int) $data['territory_id'];
        }

        return DB::transaction(function () use ($house, $changes): House {
            $old = $house->only(array_keys($changes));
            $house->update($changes);
            if ($house->wasChanged()) {
                $this->journal->record('geo.house.updated', $house, array_intersect_key($old, $house->getChanges()), array_intersect_key($changes, $house->getChanges()));
            }

            return $house;
        });
    }

    /**
     * Adds flats numbered from … to …; numbers that exist are left as they are.
     *
     * @return int flats added
     */
    public function addApartments(User $actor, House $house, int $from, int $to, ?int $entrance = null, ?int $floor = null): int
    {
        $this->access->authorize($actor, 'geo.houses.manage', $house);
        if ($from < 1 || $to < $from || $to - $from >= self::MAX_APARTMENTS) {
            throw GeoRuleViolation::because('invalid_apartment_range');
        }

        return DB::transaction(function () use ($house, $from, $to, $entrance, $floor): int {
            $added = $this->generate($house, $from, $to, $entrance, $floor);
            if ($added > 0) {
                $this->journal->record('geo.house.apartments_added', $house, [], ['from' => $from, 'to' => $to, 'added' => $added]);
            }

            return $added;
        });
    }

    public function removeApartment(User $actor, Apartment $apartment): void
    {
        $this->access->authorize($actor, 'geo.houses.manage', $apartment->house);
        if ($apartment->visits()->exists()) {
            throw GeoRuleViolation::because('apartment_has_visits');
        }
        DB::transaction(function () use ($apartment): void {
            $this->journal->record('geo.house.apartment_removed', $apartment->house, ['number' => $apartment->number]);
            $apartment->delete();
        });
    }

    public function archive(User $actor, House $house, bool $archived = true): House
    {
        $this->access->authorize($actor, 'geo.houses.manage', $house);
        if ($house->isArchived() === $archived) {
            return $house;
        }

        return DB::transaction(function () use ($house, $archived): House {
            $house->update(['archived_at' => $archived ? now() : null]);
            $this->journal->record($archived ? 'geo.house.archived' : 'geo.house.restored', $house);

            return $house;
        });
    }

    /**
     * ФО §6.11: "связанные обращения жителей". Whoever opens the house and the appeal may bind them.
     */
    public function linkAppeal(User $actor, House $house, Appeal $appeal): void
    {
        $this->access->authorize($actor, 'geo.houses.read', $house);
        if (! $this->authorization->can($actor, 'appeals.read', $appeal)) {
            throw new AuthorizationException(__('access.denied'));
        }
        $house->appeals()->syncWithoutDetaching([$appeal->id => ['linked_by_person_id' => $actor->person_id, 'created_at' => now()]]);
    }

    public function unlinkAppeal(User $actor, House $house, Appeal $appeal): void
    {
        $this->access->authorize($actor, 'geo.houses.read', $house);
        $house->appeals()->detach($appeal->id);
    }

    /**
     * The settlement a street is kept under: the nearest node above the polling district.
     */
    private function settlement(Territory $territory): Territory
    {
        while (in_array($territory->level, [Territory::ELECTORAL_AREA, Territory::SECTOR], true) && $territory->parent !== null) {
            $territory = $territory->parent;
        }

        return $territory;
    }

    private function type(string $code): CatalogItem
    {
        return CatalogItem::query()->ofCatalog('house_types')->selectable()->where('code', $code)->first()
            ?? throw GeoRuleViolation::because('unknown_house_type');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        $details = array_intersect_key($data, array_flip(['entrances', 'floors', 'residents_count', 'latitude', 'longitude', 'description']));
        foreach (['entrances', 'floors', 'residents_count'] as $key) {
            if (array_key_exists($key, $details)) {
                $details[$key] = filled($details[$key]) ? max(0, (int) $details[$key]) : null;
            }
        }
        $hasPoint = filled($details['latitude'] ?? null) && filled($details['longitude'] ?? null);
        if (array_key_exists('latitude', $details) || array_key_exists('longitude', $details)) {
            if ($hasPoint && (abs((float) $details['latitude']) > 90 || abs((float) $details['longitude']) > 180)) {
                throw GeoRuleViolation::because('invalid_point');
            }
            $details['latitude'] = $hasPoint ? (float) $details['latitude'] : null;
            $details['longitude'] = $hasPoint ? (float) $details['longitude'] : null;
        }

        return $details;
    }

    /**
     * Flats from … to …, spread evenly over the entrances and floors the house is said to have.
     */
    private function generate(House $house, int $from, int $to, ?int $entrance = null, ?int $floor = null): int
    {
        if ($to - $from >= self::MAX_APARTMENTS) {
            throw GeoRuleViolation::because('invalid_apartment_range');
        }
        $existing = Apartment::query()->where('house_id', $house->id)->pluck('number')->flip();
        $total = $to - $from + 1;
        $entrances = max(1, (int) ($house->entrances ?? 1));
        $perEntrance = (int) ceil($total / $entrances);
        $floors = max(1, (int) ($house->floors ?? 1));
        $perFloor = max(1, (int) ceil($perEntrance / $floors));

        $rows = [];
        for ($number = $from; $number <= $to; $number++) {
            if ($existing->has((string) $number)) {
                continue;
            }
            $index = $number - $from;
            $rows[] = [
                'house_id' => $house->id,
                'number' => (string) $number,
                'entrance' => $entrance ?? ($house->entrances !== null ? intdiv($index, $perEntrance) + 1 : null),
                'floor' => $floor ?? ($house->floors !== null ? intdiv($index % $perEntrance, $perFloor) + 1 : null),
                'sort_order' => $number,
                'status_code' => Apartment::NOT_VISITED,
                'attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            Apartment::query()->insert($chunk);
        }

        return count($rows);
    }
}
