<?php

use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\Actions\ManageZones;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\GeoService;
use App\Domain\Geo\Models\FieldAssignment;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Street;
use App\Domain\Geo\Models\Vehicle;
use App\Domain\Geo\Models\Visit;
use App\Filament\Pages\FieldMap;
use App\Filament\Pages\FieldSettingsPage;
use App\Filament\Pages\FieldSummary;
use App\Filament\Resources\GeoZones\Pages\ListGeoZones;
use App\Filament\Resources\GeoZones\Pages\ViewGeoZone;
use App\Filament\Resources\Houses\Pages\ListHouses;
use App\Filament\Resources\Houses\Pages\ViewHouse;
use App\Filament\Resources\Streets\Pages\ListStreets;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use Livewire\Livewire;
use Tests\Support\FieldFixture;

/*
 * The screens of the field work: thin pages over the domain (ТЗ §8). What a page shows and lets do is what the
 * domain allows — checked here through the pages themselves.
 */

beforeEach(function () {
    $this->field = FieldFixture::build();
    $this->org = $this->field->org;
});

it('lists the houses the reader may open, and adds one', function () {
    $o = $this->org;
    $f = $this->field;

    $this->actingAs($o->headA);
    Livewire::test(ListHouses::class)
        ->assertCanSeeTableRecords([$f->houseA, $f->houseA2])->assertCanNotSeeTableRecords([$f->houseB, $f->houseBalti])
        ->assertActionVisible('create')
        ->callAction('create', data: ['territory_id' => $f->c1->id, 'street' => 'strada Ismail', 'number' => '16', 'type_code' => 'apartment_building', 'apartments' => 4])
        ->assertHasNoActionErrors();
    expect(House::query()->whereHas('address', fn ($address) => $address->where('number', '16'))->sole()->apartments()->count())->toBe(4);

    $this->flushSession();
    $this->actingAs($o->a1);
    Livewire::test(ListHouses::class)->assertCanSeeTableRecords([$f->houseA])->assertCanNotSeeTableRecords([$f->houseA2, $f->houseB])
        ->assertActionHidden('create');
    $this->get('/admin/houses/'.$f->houseA->id)->assertOk()->assertSee('str. Ismail 12');
    $this->get('/admin/houses/'.$f->houseA2->id)->assertNotFound();

    $this->flushSession();
    $this->actingAs($o->central1)->get('/admin/houses')->assertOk();
    $this->flushSession();
    $this->actingAs(userWithRoles('candidate'))->get('/admin/houses')->assertForbidden();
});

it('records a visit and assigns an agitator on the page of a house', function () {
    $o = $this->org;
    $f = $this->field;
    $f->visit($o->a1, $f->houseA, '1', 'contacted', ['note' => 'Nota personală a lui a1', 'note_visibility' => 'personal']);
    $f->visit($o->a1, $f->houseA, '2', 'supporter', ['note' => 'Pentru stat-major', 'note_visibility' => 'team']);

    $this->actingAs($o->a1);
    Livewire::test(ViewHouse::class, ['record' => $f->houseA->id])
        ->assertSee('Nota personală a lui a1')->assertSee('Pentru stat-major')
        ->assertActionHidden('assign')
        ->callAction('visit', data: ['status_code' => 'not_home', 'next_visit_on' => now()->addDays(2)->toDateString(), 'note' => 'Vecinii spun: seara'], arguments: ['apartment' => $f->flat($f->houseA, '3')->id])
        ->assertHasNoActionErrors();
    expect($f->flat($f->houseA, '3'))->toMatchArray(['status_code' => 'not_home', 'attempts' => 1])
        ->and(Visit::query()->where('source', 'online')->count())->toBe(3);

    // The head: the notes for the staff, not the personal one; assigns; does not record visits.
    $this->flushSession();
    $this->actingAs($o->headA);
    Livewire::test(ViewHouse::class, ['record' => $f->houseA->id])
        ->assertSee('Pentru stat-major')->assertDontSee('Nota personală a lui a1')
        ->assertSee($o->a1->person->fullName())
        ->callAction('assign', data: ['person_id' => $o->a2->person_id])->assertHasNoActionErrors()
        ->callAction('endAssignment', arguments: ['assignment' => FieldAssignment::query()->where('person_id', $o->a2->person_id)->value('id')])
        ->callAction('addApartments', data: ['from' => 13, 'to' => 14])
        ->callAction('visit', data: ['status_code' => 'supporter'], arguments: ['apartment' => $f->flat($f->houseA, '4')->id]);
    expect(FieldAssignment::query()->where('person_id', $o->a2->person_id)->whereNotNull('ended_at')->count())->toBe(1)
        ->and($f->houseA->apartments()->count())->toBe(14)
        ->and($f->flat($f->houseA, '4')->status_code)->toBe('not_visited');
});

it('shows the map and the summary within the reach of the reader', function () {
    $o = $this->org;
    $f = $this->field;
    $f->visit($o->a1, $f->houseA, '1', 'supporter');

    $this->actingAs($o->headA);
    Livewire::test(FieldMap::class)->assertOk()->assertSee('data-houses="2"', false)->assertSee('str. Ismail 12', false)->assertDontSee('bd. Dacia 20', false)
        ->call('refreshMovers')->assertDispatched('bz-map-movers');
    expect(Livewire::test(FieldMap::class)->instance()->boundaries())->toBe(app(GeoService::class)->boundaries());
    Livewire::test(FieldSummary::class)->assertOk()->assertSee('chisinau/centru')->assertDontSee('chisinau/botanica');
    Livewire::test(FieldSummary::class, ['territory' => $o->centru->id])->assertSee('str. Ismail 12')->assertSee('bd. Ștefan cel Mare 5');

    // An agitator has the map — of their own territories — and no summary.
    $this->flushSession();
    $this->actingAs($o->b1);
    Livewire::test(FieldMap::class)->assertOk()->assertSee('data-houses="1"', false)->assertDontSee('str. Ismail 12', false);
    $this->get('/admin/field-summary')->assertForbidden();
});

it('draws a geozone on its page', function () {
    $o = $this->org;
    $this->actingAs($o->headA);

    Livewire::test(ListGeoZones::class)
        ->callAction('create', data: ['name' => 'Piața Centrală', 'territory_id' => $o->centru->id, 'color' => '#dc2626', 'latitude' => 47.02, 'longitude' => 28.84, 'responsible_ids' => [$o->a1->person_id]])
        ->assertHasNoActionErrors();
    $zone = GeoZone::query()->sole();
    expect(app(GeoService::class)->zoneContains($zone, 47.02, 28.84))->toBeTrue()
        ->and($zone->color)->toBe('#dc2626');

    $corners = [[47.03, 28.83], [47.03, 28.85], [47.01, 28.85], [47.01, 28.83], [47.02, 28.82]];
    Livewire::test(ViewGeoZone::class, ['record' => $zone->id])->assertSee('Piața Centrală')->assertSee($o->a1->person->fullName())
        ->call('saveOutline', $corners);
    expect(app(GeoService::class)->zoneCorners($zone->fresh()))->toHaveCount(5)
        ->and(app(GeoService::class)->zoneContains($zone->fresh(), 47.029, 28.849))->toBeTrue();

    // Whoever only sees the zone cannot reshape it — the page asks the domain.
    $this->flushSession();
    $this->actingAs($o->a2);
    Livewire::test(ListGeoZones::class)->assertCanSeeTableRecords([$zone])->assertActionHidden('create');
    Livewire::test(ViewGeoZone::class, ['record' => $zone->id])->assertActionHidden('edit')->call('saveOutline', [[47.5, 28.5], [47.6, 28.5], [47.6, 28.6]]);
    expect(app(GeoService::class)->zoneCorners($zone->fresh()))->toHaveCount(5);

    $this->flushSession();
    $this->actingAs($o->b1)->get('/admin/geo-zones/'.$zone->id)->assertNotFound();
});

it('keeps the address directory, the vehicles and the settings on their pages', function () {
    $o = $this->org;
    $street = $this->field->houseA->address->street;

    $this->actingAs($o->admin);
    Livewire::test(ListStreets::class)->assertCanSeeTableRecords(Street::query()->get())
        ->callTableAction('rename', $street, data: ['name' => 'str. Ismail Veche']);
    expect($street->fresh()->name)->toBe('str. Ismail Veche');

    Livewire::test(ListVehicles::class)->callAction('create', data: ['name' => 'Microbuz', 'type_code' => 'minibus', 'org_unit_id' => $o->branchA->id]);
    $bus = Vehicle::query()->sole();
    Livewire::test(ListVehicles::class)->callTableAction('issueKey', $bus)->assertNotified();
    expect($bus->fresh()->hasTracker())->toBeTrue();

    Livewire::test(FieldSettingsPage::class)->assertSet('values.houses_per_agitator', 3)
        ->set('values.houses_per_agitator', 4)->set('shareMinutes', '30, 90')->call('save');
    expect(app(FieldSettings::class)->housesPerAgitator())->toBe(4)->and(app(FieldSettings::class)->shareMinutes())->toBe([30, 90]);

    // The directory and the settings are closed to the heads; the vehicles of another branch are not listed.
    $this->flushSession();
    $this->actingAs($o->headB);
    $this->get('/admin/streets')->assertForbidden();
    $this->get('/admin/field-settings')->assertForbidden();
    Livewire::test(ListVehicles::class)->assertCanNotSeeTableRecords([$bus]);
    expect(app(ManageVehicles::class)->visibleTo($o->headA)->count())->toBe(1)
        ->and(app(ManageZones::class)->visibleTo($o->headB)->count())->toBe(0);
});
