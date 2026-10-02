<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Geo\AddressNormalizer;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\Models\Address;
use App\Domain\Geo\Models\Street;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The single address directory (ФО §6.11). A street typed in any spelling lands on the row that already exists;
 * renaming and merging streets is the work of those who keep the directory (`geo.addresses.manage`).
 */
final readonly class ManageAddresses
{
    public function __construct(
        private AddressNormalizer $normalizer,
        private AuthorizationService $authorization,
        private EventJournal $journal,
    ) {}

    /**
     * Finds the street by what was typed, or adds it. Not a right of its own: it happens while a house is added.
     */
    public function street(Territory $territory, string $typed): Street
    {
        $parsed = $this->normalizer->street($typed);
        if ($parsed['key'] === '') {
            throw GeoRuleViolation::because('street_required');
        }

        return Street::query()->firstOrCreate(
            ['territory_id' => $territory->id, 'type_code' => $parsed['type'], 'normalized' => $parsed['key']],
            ['name' => $this->display($parsed['type'], $parsed['name'])],
        );
    }

    public function address(Street $street, string $typedNumber): Address
    {
        $number = $this->normalizer->number($typedNumber);
        if ($number === '') {
            throw GeoRuleViolation::because('number_required');
        }

        return Address::query()->firstOrCreate(
            ['street_id' => $street->id, 'normalized_number' => $number],
            ['number' => $number],
        );
    }

    public function rename(User $actor, Street $street, string $typed): Street
    {
        $this->authorization->authorize($actor, 'geo.addresses.manage');
        $parsed = $this->normalizer->street($typed);
        if ($parsed['key'] === '') {
            throw GeoRuleViolation::because('street_required');
        }
        $twin = Street::query()->where('territory_id', $street->territory_id)->where('type_code', $parsed['type'])
            ->where('normalized', $parsed['key'])->whereKeyNot($street->id)->first();
        if ($twin !== null) {
            throw GeoRuleViolation::because('street_exists', ['name' => $twin->name]);
        }

        return DB::transaction(function () use ($street, $parsed): Street {
            $old = $street->name;
            $street->update(['type_code' => $parsed['type'], 'normalized' => $parsed['key'], 'name' => $this->display($parsed['type'], $parsed['name'])]);
            $this->journal->record('geo.street.renamed', $street, ['name' => $old], ['name' => $street->name]);

            return $street;
        });
    }

    /**
     * Two entries turned out to be one street: its addresses move to the one that stays.
     */
    public function merge(User $actor, Street $duplicate, Street $into): Street
    {
        $this->authorization->authorize($actor, 'geo.addresses.manage');
        if ($duplicate->id === $into->id || $duplicate->territory_id !== $into->territory_id) {
            throw GeoRuleViolation::because('streets_not_mergeable');
        }

        return DB::transaction(function () use ($duplicate, $into): Street {
            foreach (Address::query()->where('street_id', $duplicate->id)->with('house')->get() as $address) {
                $twin = Address::query()->where('street_id', $into->id)->where('normalized_number', $address->normalized_number)->with('house')->first();
                if ($twin === null) {
                    $address->update(['street_id' => $into->id]);

                    continue;
                }
                if ($address->house !== null && $twin->house !== null) {
                    // Two cards of one house: a person must decide which one stays.
                    throw GeoRuleViolation::because('merge_conflict', ['number' => $address->number]);
                }
                if ($address->house !== null) {
                    $address->house->update(['address_id' => $twin->id]);
                }
                $address->delete();
            }
            $name = $duplicate->name;
            $duplicate->delete();
            $this->journal->record('geo.street.merged', $into, ['merged' => $name], ['name' => $into->name]);

            return $into;
        });
    }

    private function display(string $type, string $name): string
    {
        $abbr = CatalogItem::query()->ofCatalog('street_types')->where('code', $type)->first()?->property('abbr');

        return trim(($abbr !== null ? $abbr.' ' : '').$name);
    }
}
