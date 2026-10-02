<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Access\AuthorizationService;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\Geo\Actions\ManageAssignments;
use App\Domain\Geo\Actions\ManageHouses;
use App\Domain\Geo\Actions\RecordVisits;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\Models\Apartment;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Models\Visit;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Tasks\TaskWorkflow;

/**
 * Field work on top of the small organization of OrgFixture:
 *
 *   Centru ─┬─ polling district C-1: houseA (12 flats, 2 entrances) — a1
 *           └─ polling district C-2: houseA2 (6 flats)              — the volunteer, by the whole district
 *   Botanica ─ polling district B-1: houseB (8 flats)               — b1
 *   Bălți ──── polling district BL-1: houseBalti (4 flats)
 */
final class FieldFixture
{
    public OrgFixture $org;

    public Territory $c1;

    public Territory $c2;

    public Territory $b1Area;

    public Territory $baltiArea;

    public House $houseA;

    public House $houseA2;

    public House $houseB;

    public House $houseBalti;

    public User $volunteer;

    public static function build(): self
    {
        $f = new self;
        $f->org = $o = OrgFixture::build();
        app(ImportReferenceCatalogs::class)();
        TaskWorkflow::ensureDefaults();

        $f->c1 = OrgFixture::territory('chisinau/centru/1', $o->centru, Territory::ELECTORAL_AREA);
        $f->c2 = OrgFixture::territory('chisinau/centru/2', $o->centru, Territory::ELECTORAL_AREA);
        $f->b1Area = OrgFixture::territory('chisinau/botanica/1', $o->botanica, Territory::ELECTORAL_AREA);
        $f->baltiArea = OrgFixture::territory('balti/1', $o->baltiTerritory, Territory::ELECTORAL_AREA);

        $f->volunteer = userWithRoles('volunteer');
        app(ManageMembership::class)->place($o->admin, $f->volunteer->person, $o->branchA);
        self::forget();

        $houses = app(ManageHouses::class);
        $f->houseA = $houses->create($o->headA, [
            'territory_id' => $f->c1->id, 'street' => 'str. Ismail', 'number' => '12', 'entrances' => 2, 'floors' => 3, 'apartments' => 12,
            'latitude' => 47.0200, 'longitude' => 28.8400,
        ]);
        $f->houseA2 = $houses->create($o->headA, [
            'territory_id' => $f->c2->id, 'street' => 'bd. Ștefan cel Mare', 'number' => '5', 'apartments' => 6, 'latitude' => 47.0300, 'longitude' => 28.8300,
        ]);
        $f->houseB = $houses->create($o->headB, [
            'territory_id' => $f->b1Area->id, 'street' => 'bd. Dacia', 'number' => '20', 'apartments' => 8, 'latitude' => 46.9900, 'longitude' => 28.8600,
        ]);
        $f->houseBalti = $houses->create($o->baltiHead, [
            'territory_id' => $f->baltiArea->id, 'street' => 'str. Independenței', 'number' => '1', 'apartments' => 4, 'latitude' => 47.7600, 'longitude' => 27.9300,
        ]);

        $assignments = app(ManageAssignments::class);
        $assignments->assignHouse($o->headA, $f->houseA, $o->a1->person);
        $assignments->assignTerritory($o->headA, $f->c2, $f->volunteer->person);
        $assignments->assignHouse($o->headB, $f->houseB, $o->b1->person);
        self::forget();

        return $f;
    }

    public static function forget(): void
    {
        app(AuthorizationService::class)->forget();
        app(FieldAccess::class)->forget();
    }

    public function flat(House $house, string $number): Apartment
    {
        return Apartment::query()->where('house_id', $house->id)->where('number', $number)->sole();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function visit(User $by, House $house, string $number, string $status, array $data = []): Visit
    {
        return app(RecordVisits::class)->record($by, $this->flat($house, $number), ['status_code' => $status, ...$data]);
    }
}
