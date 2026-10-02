<?php

namespace Database\Seeders\Demo;

use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\Models\Event;
use App\Domain\Geo\Actions\LocationSharing;
use App\Domain\Geo\Actions\ManageAddresses;
use App\Domain\Geo\Actions\ManageAssignments;
use App\Domain\Geo\Actions\ManageHouses;
use App\Domain\Geo\Actions\ManageTerritories;
use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\Actions\ManageZones;
use App\Domain\Geo\Actions\OfflineSync;
use App\Domain\Geo\Actions\RecordVisits;
use App\Domain\Geo\Models\Apartment;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Street;
use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Models\Vehicle;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Field work of the demo world (phase 7, docs/demo/README.md § Полевая работа). The geography is real — the
 * sectors of Chișinău, Bălți — but the polling districts, streets and houses are invented, and so is every
 * result of every visit: nothing here says anything about real residents.
 *
 * Polling districts in two regions, houses with flats in every status of the contact, several attempts, notes for
 * oneself and for the staff, agitators with their houses, a geozone with an event bound to it, a queue of offline
 * operations sent twice, a person sharing their location, a vehicle with a tracker.
 */
class FieldDemoSeeder extends Seeder
{
    use DemoSteps;

    public const string AREA_C12 = 'Secția de votare nr. 12';

    public const string AREA_C15 = 'Secția de votare nr. 15';

    public const string AREA_B41 = 'Secția de votare nr. 41';

    public const string AREA_BL7 = 'Secția de votare nr. 7';

    /** Ion's house: flats in every status, several attempts, notes of both kinds. */
    public const string HOUSE_ION = 'str. Teilor 12';

    /** Radu's houses: a block of flats and a private house. */
    public const string HOUSE_RADU = 'str. Teilor 14';

    public const string HOUSE_PRIVATE = 'str. Meșterilor 3';

    /** The houses of polling district 15 — Maria's, given to her as a whole district. */
    public const string HOUSE_MARIA = 'bd. Podgorenilor 7';

    public const string HOUSE_UNTOUCHED = 'bd. Podgorenilor 9';

    public const string HOUSE_ARCHIVED = 'str. Teilor 16';

    public const string HOUSE_BOTANICA = 'str. Zorilor 20';

    public const string HOUSE_BALTI = 'str. Salcâmilor 5';

    public const string ZONE = 'Perimetrul de agitație — piața din Centru';

    public const string ZONE_BOTANICA = 'Parcul din Botanica';

    public const string EVENT_IN_ZONE = 'Cort de agitație în piață';

    public const string VEHICLE = 'Microbuzul filialei A';

    public const string VEHICLE_BALTI = 'Autoturismul organizației Bălți';

    public const string NOTE_PERSONAL = 'Câine în curte. Soneria nu merge — de bătut la geam.';

    public const string NOTE_TEAM = 'Vrea să ajute la împărțitul pliantelor, a lăsat numărul.';

    public const string DEVICE = 'demo-phone-maria';

    /** Maria's offline operations: sent on Tuesday evening, and once more the next morning. */
    public const array OPERATIONS = [
        '5f0c1d2e-0001-4a7b-9c11-000000000001', '5f0c1d2e-0001-4a7b-9c11-000000000002', '5f0c1d2e-0001-4a7b-9c11-000000000003',
    ];

    /** An operation of her phone on a flat of somebody else's house: refused, and remembered as refused. */
    public const string OPERATION_REFUSED = '5f0c1d2e-0001-4a7b-9c11-0000000000ff';

    public static function house(string $label): House
    {
        return House::query()->with('address.street')->get()->firstOrFail(fn (House $house): bool => $house->label() === $label);
    }

    public static function area(string $name): Territory
    {
        return Territory::query()->where('level', Territory::ELECTORAL_AREA)->where('name_ro', $name)->firstOrFail();
    }

    public static function flat(string $house, string $number): Apartment
    {
        return Apartment::query()->where('house_id', self::house($house)->id)->where('number', $number)->firstOrFail();
    }

    public static function zone(string $name): GeoZone
    {
        return GeoZone::query()->where('name', $name)->firstOrFail();
    }

    public function run(): void
    {
        try {
            $this->at(30, fn () => $this->pollingDistricts());
            $this->at(28, fn () => $this->houses());
            $this->at(27, fn () => $this->directory());
            $this->at(26, fn () => $this->assignments());
            $this->visits();
            $this->at(12, fn () => $this->zones());
            $this->at(2, fn () => $this->offlineQueue());
            $this->vehicles();
            $this->locations();
        } finally {
            $this->resetClock();
        }
    }

    /**
     * @template T
     *
     * @param  callable(User): T  $step
     * @return T
     */
    private function by(string $key, callable $step): mixed
    {
        $user = Personas::user($key);

        return $this->as($user, fn () => $step($user));
    }

    /**
     * A step a few minutes before "now" — for what is going on right now.
     *
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    private function minutesAgo(int $minutes, callable $step): mixed
    {
        $this->demoNow ??= Carbon::now();
        Carbon::setTestNow($this->demoNow->copy()->subMinutes($minutes));

        try {
            return $step();
        } finally {
            Carbon::setTestNow($this->demoNow);
        }
    }

    /**
     * The keeper of the catalogs adds a level below the sectors: polling districts (Д-7 — the depth can grow).
     */
    private function pollingDistricts(): void
    {
        $add = fn (User $actor, string $parent, string $name, string $number) => app(ManageTerritories::class)->add(
            $actor, Personas::territory($parent), Territory::ELECTORAL_AREA,
            ['ro' => $name, 'ru' => 'Избирательный участок № '.$number, 'en' => 'Polling district No. '.$number], 'ro',
        );
        $this->by('catalog_admin', function (User $liliana) use ($add): void {
            $add($liliana, 'chisinau/sectorul-centru', self::AREA_C12, '12');
            $add($liliana, 'chisinau/sectorul-centru', self::AREA_C15, '15');
            $add($liliana, 'chisinau/sectorul-botanica', self::AREA_B41, '41');
            $add($liliana, 'balti', self::AREA_BL7, '7');
        });
    }

    private function houses(): void
    {
        $houses = app(ManageHouses::class);
        $c12 = self::area(self::AREA_C12)->id;
        $c15 = self::area(self::AREA_C15)->id;

        $this->by('branch_a_head', function (User $ana) use ($houses, $c12, $c15): void {
            $houses->create($ana, ['territory_id' => $c12, 'street' => 'str. Teilor', 'number' => '12', 'entrances' => 2, 'floors' => 5,
                'apartments' => 20, 'residents_count' => 52, 'latitude' => 47.0222, 'longitude' => 28.8355, 'description' => 'Interfon la ambele scări.']);
            // Typed differently — still the same street of the directory.
            $houses->create($ana, ['territory_id' => $c12, 'street' => 'Strada Teilor', 'number' => '14', 'entrances' => 1, 'floors' => 4,
                'apartments' => 12, 'latitude' => 47.0226, 'longitude' => 28.8362]);
            $houses->create($ana, ['territory_id' => $c12, 'street' => 'str. Meșterilor', 'number' => '3', 'type_code' => House::PRIVATE_HOUSE,
                'latitude' => 47.0215, 'longitude' => 28.8349]);
            $houses->create($ana, ['territory_id' => $c15, 'street' => 'bd. Podgorenilor', 'number' => '7', 'entrances' => 2, 'floors' => 4,
                'apartments' => 16, 'latitude' => 47.0290, 'longitude' => 28.8250]);
            // Typed in Cyrillic: a second entry of the same boulevard appears — until the directory is put in order.
            $houses->create($ana, ['territory_id' => $c15, 'street' => 'бул. Подгоренилор', 'number' => '9', 'entrances' => 1, 'floors' => 4,
                'apartments' => 8, 'latitude' => 47.0294, 'longitude' => 28.8256]);
            $demolished = $houses->create($ana, ['territory_id' => $c12, 'street' => 'str. Teilor', 'number' => '16', 'apartments' => 4]);
            $this->at(20, fn () => $houses->archive($ana, $demolished));
        });
        $this->by('branch_b_head', fn (User $pavel) => $houses->create($pavel, [
            'territory_id' => self::area(self::AREA_B41)->id, 'street' => 'str. Zorilor', 'number' => '20', 'entrances' => 1, 'floors' => 4,
            'apartments' => 12, 'latitude' => 46.9870, 'longitude' => 28.8580,
        ]));
        $this->by('balti_head', fn (User $nicolae) => $houses->create($nicolae, [
            'territory_id' => self::area(self::AREA_BL7)->id, 'street' => 'str. Salcâmilor', 'number' => '5', 'entrances' => 1, 'floors' => 5,
            'apartments' => 10, 'latitude' => 47.7617, 'longitude' => 27.9290,
        ]));
    }

    /**
     * The keeper of the directory merges the Cyrillic entry of the boulevard into the Latin one.
     */
    private function directory(): void
    {
        $chisinau = Personas::territory('chisinau')->id;
        $this->by('catalog_admin', fn (User $liliana) => app(ManageAddresses::class)->merge(
            $liliana,
            Street::query()->where('territory_id', $chisinau)->where('name', 'like', '%Подгоренилор%')->firstOrFail(),
            Street::query()->where('territory_id', $chisinau)->where('name', 'bd. Podgorenilor')->firstOrFail(),
        ));
    }

    private function assignments(): void
    {
        $assignments = app(ManageAssignments::class);
        $this->by('branch_a_head', function (User $ana) use ($assignments): void {
            $assignments->assignHouse($ana, self::house(self::HOUSE_ION), Personas::user('branch_a_employee_1')->person);
            $assignments->assignHouse($ana, self::house(self::HOUSE_RADU), Personas::user('volunteer')->person);
            $assignments->assignHouse($ana, self::house(self::HOUSE_PRIVATE), Personas::user('volunteer')->person);
            // A whole polling district: both houses of the boulevard, and any house added there later.
            $assignments->assignTerritory($ana, self::area(self::AREA_C15), Personas::user('branch_a_employee_2')->person);
            // Sergiu helped Ion for a week; the assignment was then taken back — he no longer sees the house.
            $helper = $assignments->assignHouse($ana, self::house(self::HOUSE_ION), Personas::user('branch_a_employee_3')->person);
            $this->at(19, fn () => $assignments->end($ana, $helper));
        });
        $this->by('branch_b_head', fn (User $pavel) => $assignments->assignHouse($pavel, self::house(self::HOUSE_BOTANICA), Personas::user('branch_b_employee_1')->person));
        $this->by('balti_head', fn (User $nicolae) => $assignments->assignHouse($nicolae, self::house(self::HOUSE_BALTI), Personas::user('balti_employee_2')->person));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function visit(string $key, string $house, string $flat, string $status, array $data = []): void
    {
        $this->by($key, fn (User $agitator) => app(RecordVisits::class)->record($agitator, self::flat($house, $flat), ['status_code' => $status, ...$data]));
    }

    private function visits(): void
    {
        $ion = 'branch_a_employee_1';
        $h = self::HOUSE_ION;

        // Ion's block: the first round two weeks ago, the second a week ago, the third the day before yesterday.
        $this->at(14, function () use ($ion, $h): void {
            $this->visit($ion, $h, '1', 'supporter', ['note' => self::NOTE_TEAM, 'note_visibility' => 'team']);
            $this->visit($ion, $h, '2', 'supporter');
            $this->visit($ion, $h, '3', 'opposed');
            $this->visit($ion, $h, '4', 'undecided', ['note' => 'A cerut programul tipărit.', 'note_visibility' => 'team']);
            $this->visit($ion, $h, '5', 'contacted');
            $this->visit($ion, $h, '6', 'refused', ['note' => self::NOTE_PERSONAL, 'note_visibility' => 'personal']);
            $this->visit($ion, $h, '7', 'not_home');
            $this->visit($ion, $h, '8', 'not_home');
            $this->visit($ion, $h, '9', 'not_home');
        });
        $this->at(7, function () use ($ion, $h): void {
            $this->visit($ion, $h, '7', 'not_home');
            $this->visit($ion, $h, '8', 'not_home');
            // To come back yesterday — and nobody came: the flat is overdue.
            $this->visit($ion, $h, '9', 'not_home', ['next_visit_on' => now()->addDays(6)->toDateString()]);
            $this->visit($ion, $h, '10', 'supporter');
        });
        $this->at(2, function () use ($ion, $h): void {
            // The third attempt: at home at last.
            $this->visit($ion, $h, '8', 'supporter', ['note' => 'Seara, după ora 19.', 'note_visibility' => 'personal']);
            // To come back in three days, with a task for himself.
            $this->visit($ion, $h, '7', 'not_home', ['next_visit_on' => now()->addDays(5)->toDateString(), 'create_task' => true]);
            $this->visit($ion, $h, '11', 'undecided');
        });

        // Radu, a volunteer: his block and the private house; a task to come back is set by the volunteer himself.
        $this->at(9, function (): void {
            $this->visit('volunteer', self::HOUSE_RADU, '1', 'contacted');
            $this->visit('volunteer', self::HOUSE_RADU, '2', 'supporter', ['note' => 'Pensionar, vrea transport în ziua alegerilor.', 'note_visibility' => 'team']);
            $this->visit('volunteer', self::HOUSE_RADU, '3', 'not_home', ['next_visit_on' => now()->addDays(11)->toDateString(), 'create_task' => true]);
            $this->visit('volunteer', self::HOUSE_PRIVATE, '1', 'supporter');
        });

        // Maria, online, in her polling district — before the evening without a network.
        $this->at(5, function (): void {
            $this->visit('branch_a_employee_2', self::HOUSE_MARIA, '1', 'supporter');
            $this->visit('branch_a_employee_2', self::HOUSE_MARIA, '2', 'opposed');
            $this->visit('branch_a_employee_2', self::HOUSE_MARIA, '3', 'not_home');
        });

        $this->at(6, function (): void {
            foreach (['1' => 'supporter', '2' => 'undecided', '3' => 'refused', '4' => 'not_home', '5' => 'contacted'] as $flat => $status) {
                $this->visit('branch_b_employee_1', self::HOUSE_BOTANICA, (string) $flat, $status);
            }
        });
        $this->at(4, function (): void {
            foreach (['1' => 'supporter', '2' => 'supporter', '3' => 'opposed'] as $flat => $status) {
                $this->visit('balti_employee_2', self::HOUSE_BALTI, (string) $flat, $status);
            }
        });
    }

    /**
     * A geozone with people who answer for it, and an event that falls inside it by its point on the map.
     */
    private function zones(): void
    {
        $zones = app(ManageZones::class);
        $square = fn (float $latitude, float $longitude, float $half = 0.003): array => [
            [$latitude + $half, $longitude - $half * 1.5], [$latitude + $half, $longitude + $half * 1.5],
            [$latitude - $half, $longitude + $half * 1.5], [$latitude - $half, $longitude - $half * 1.5],
        ];

        $this->by('branch_a_head', function (User $ana) use ($zones, $square): void {
            $zones->create($ana, [
                'name' => self::ZONE, 'territory_id' => Personas::territory('chisinau/sectorul-centru')->id, 'color' => '#dc2626',
                'description' => 'Zona de lucru a echipei în săptămânile de agitație: casele de pe str. Teilor și str. Meșterilor.',
                'corners' => $square(47.0222, 28.8355),
                'responsible_ids' => [$ana->person_id, Personas::user('branch_a_employee_1')->person_id],
            ]);
            // In three days, on the square — inside the outline: bound by itself, and Ion is told.
            $this->at(3, fn () => app(ManageEvents::class)->create($ana, [
                'title' => self::EVENT_IN_ZONE, 'type_code' => 'canvassing', 'starts_at' => now()->addDays(6)->setTime(11, 0),
                'ends_at' => now()->addDays(6)->setTime(15, 0), 'location' => 'Piața, lângă fântână', 'latitude' => 47.0224, 'longitude' => 28.8358,
                'visibility' => Event::REGIONAL, 'territory_ids' => [Personas::territory('chisinau/sectorul-centru')->id],
            ]));
        });
        $this->by('branch_b_head', fn (User $pavel) => $zones->create($pavel, [
            'name' => self::ZONE_BOTANICA, 'territory_id' => Personas::territory('chisinau/sectorul-botanica')->id, 'color' => '#16a34a',
            'corners' => $square(46.9870, 28.8580, 0.004),
        ]));
    }

    /**
     * An evening without a network: three visits wait on Maria's phone, are sent — and the next morning the phone,
     * which never got the answer, sends them again. Three visits, not six.
     */
    private function offlineQueue(): void
    {
        $operation = fn (string $id, string $house, string $flat, array $payload, int $minutesAgo): array => [
            'operation_id' => $id, 'entity' => 'apartment', 'entity_id' => self::flat($house, $flat)->id, 'operation' => 'visit',
            'payload' => $payload, 'client_timestamp' => now()->subMinutes($minutesAgo)->toIso8601String(),
        ];
        $batch = [
            $operation(self::OPERATIONS[0], self::HOUSE_MARIA, '4', ['status_code' => 'supporter'], 95),
            $operation(self::OPERATIONS[1], self::HOUSE_MARIA, '5', ['status_code' => 'not_home', 'next_visit_on' => now()->addDays(4)->toDateString()], 80),
            $operation(self::OPERATIONS[2], self::HOUSE_MARIA, '6', ['status_code' => 'undecided', 'note' => 'Fără semnal la scară — notat pe loc.', 'note_visibility' => 'team'], 65),
            // A flat of Ion's house got into her queue by mistake: the server refuses it.
            $operation(self::OPERATION_REFUSED, self::HOUSE_ION, '12', ['status_code' => 'supporter'], 50),
        ];

        $this->by('branch_a_employee_2', function (User $maria) use ($batch): void {
            app(OfflineSync::class)->apply($maria, self::DEVICE, $batch);
            $this->at(1, fn () => app(OfflineSync::class)->apply($maria, self::DEVICE, $batch));
        });
    }

    private function vehicles(): void
    {
        $vehicles = app(ManageVehicles::class);
        $key = $this->at(20, fn () => $this->by('branch_a_head', function (User $ana) use ($vehicles): string {
            $bus = $vehicles->create($ana, [
                'name' => self::VEHICLE, 'plate' => 'C DM 707', 'type_code' => 'minibus', 'org_unit_id' => Personas::unit('branch_a')->id,
                'responsible_person_id' => Personas::user('branch_a_employee_3')->person_id, 'description' => 'Pentru ieșirile în teren și transportul materialelor.',
            ]);

            return $vehicles->issueKey($ana, $bus);
        }));
        $this->at(18, fn () => $this->by('balti_head', fn (User $nicolae) => $vehicles->create($nicolae, [
            'name' => self::VEHICLE_BALTI, 'plate' => 'B DM 112', 'type_code' => 'car', 'org_unit_id' => Personas::unit('balti')->id,
        ])));

        // This morning the tracker reported the way from the office to the square, and on.
        $at = fn (int $minutesAgo): string => $this->demoNow->copy()->subMinutes($minutesAgo)->toIso8601String();
        foreach ([[70, 47.0105, 28.8638], [60, 47.0180, 28.8450], [50, 47.0222, 28.8356], [35, 47.0223, 28.8357], [15, 47.0290, 28.8250]] as [$minutes, $latitude, $longitude]) {
            $this->minutesAgo($minutes, fn () => $vehicles->report($key, [['latitude' => $latitude, 'longitude' => $longitude, 'recorded_at' => $at($minutes)]]));
        }
    }

    /**
     * Voluntary location sharing: over, stopped by the person, and going on right now.
     */
    private function locations(): void
    {
        $sharing = app(LocationSharing::class);

        // Yesterday Maria shared for an hour; the hour ran out by itself.
        $this->at(1, fn () => $this->by('branch_a_employee_2', function (User $maria) use ($sharing): void {
            $sharing->start($maria, 60);
            $sharing->report($maria, 47.0290, 28.8250, 15);
            $sharing->report($maria, 47.0294, 28.8256, 12);
        }));
        // Radu turned it on and stopped it himself a little later.
        $this->at(1, fn () => $this->by('volunteer', function (User $radu) use ($sharing): void {
            $sharing->start($radu, 240);
            $sharing->report($radu, 47.0226, 28.8362, 20);
            $sharing->stop($radu);
        }));
        // Ion is sharing now: he came to the square 20 minutes ago — Ana, who answers for the zone, was told.
        $this->minutesAgo(25, fn () => $this->by('branch_a_employee_1', function (User $ion) use ($sharing): void {
            $sharing->start($ion, 240);
            $sharing->report($ion, 47.0300, 28.8400, 18);
        }));
        $this->minutesAgo(20, fn () => $this->by('branch_a_employee_1', fn (User $ion) => $sharing->report($ion, 47.0223, 28.8356, 9)));
        $this->minutesAgo(5, fn () => $this->by('branch_a_employee_1', fn (User $ion) => $sharing->report($ion, 47.0221, 28.8353, 8)));
    }
}
