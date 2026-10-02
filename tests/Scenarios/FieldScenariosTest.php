<?php

use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Geo\Actions\LocationSharing;
use App\Domain\Geo\Actions\ManageAssignments;
use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\Actions\ManageZones;
use App\Domain\Geo\Actions\OfflineSync;
use App\Domain\Geo\Actions\RecordVisits;
use App\Domain\Geo\CanvassSummary;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\FieldNotes;
use App\Domain\Geo\FieldSnapshot;
use App\Domain\Geo\MapData;
use App\Domain\Geo\Models\Apartment;
use App\Domain\Geo\Models\FieldOperation;
use App\Domain\Geo\Models\GeoZoneCrossing;
use App\Domain\Geo\Models\GeoZoneLink;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Geo\Models\Street;
use App\Domain\Geo\Models\Vehicle;
use App\Domain\Geo\Models\Visit;
use App\Domain\Tasks\Models\Task;
use Database\Seeders\Demo\FieldDemoSeeder as Field;
use Database\Seeders\Demo\Personas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
 * IMPLEMENTATION-PLAN 7.3 — the field work on the demo world (docs/demo/README.md § Полевая работа).
 */

function fieldHouses(string $persona, string $code = 'geo.houses.read'): array
{
    return app(FieldAccess::class)->houses(Personas::user($persona), $code)->with('address.street')->get()
        ->map(fn ($house): string => $house->label())->sort()->values()->all();
}

function fieldNotices(string $persona, string $kind): array
{
    return Personas::user($persona)->notifications()->where('data->kind', $kind)->get()->map(fn ($n): string => (string) $n->data['title'])->all();
}

it('shows an agitator only the houses they answer for, and lets them work only there', function () {
    expect(fieldHouses('branch_a_employee_1'))->toBe([Field::HOUSE_ION])
        ->and(fieldHouses('volunteer'))->toBe([Field::HOUSE_PRIVATE, Field::HOUSE_RADU])
        // A polling district given as a whole: both houses of the boulevard.
        ->and(fieldHouses('branch_a_employee_2'))->toBe([Field::HOUSE_MARIA, Field::HOUSE_UNTOUCHED])
        // Sergiu helped for a week; the assignment was taken back.
        ->and(fieldHouses('branch_a_employee_3'))->toBe([])
        ->and(fieldHouses('branch_b_employee_1'))->toBe([Field::HOUSE_BOTANICA])
        ->and(fieldHouses('branch_a_employee_1', 'geo.houses.manage'))->toBe([]);

    $ion = Personas::user('branch_a_employee_1');
    $visits = app(RecordVisits::class);
    expect($visits->record($ion, Field::flat(Field::HOUSE_ION, '12'), ['status_code' => 'contacted'])->attempt_no)->toBe(1)
        ->and(fn () => $visits->record($ion, Field::flat(Field::HOUSE_RADU, '5'), ['status_code' => 'supporter']))->toThrow(AuthorizationException::class)
        ->and(fn () => $visits->record(Personas::user('branch_a_employee_3'), Field::flat(Field::HOUSE_ION, '13'), ['status_code' => 'supporter']))->toThrow(AuthorizationException::class)
        // The head of the branch sees the house and does not record visits in it.
        ->and(fn () => $visits->record(Personas::user('branch_a_head'), Field::flat(Field::HOUSE_ION, '13'), ['status_code' => 'supporter']))->toThrow(AuthorizationException::class)
        ->and(array_column(app(FieldSnapshot::class)->for($ion)['houses'], 'label'))->toBe([Field::HOUSE_ION]);

    $this->actingAs($ion)->get('/admin/houses')->assertOk()->assertSee(Field::HOUSE_ION)->assertDontSee(Field::HOUSE_RADU)->assertDontSee(Field::HOUSE_BOTANICA);
    $this->get('/admin/houses/'.Field::house(Field::HOUSE_ION)->id)->assertOk();
    $this->get('/admin/houses/'.Field::house(Field::HOUSE_RADU)->id)->assertNotFound();
    $this->get('/admin/field-summary')->assertForbidden();
    $this->get('/field')->assertOk();
});

it('gives the head of a district the summary of the whole district — and nothing of the next one', function () {
    $summary = app(CanvassSummary::class);
    $rows = fn (string $persona): array => collect($summary->byTerritory(Personas::user($persona)))
        ->mapWithKeys(fn (array $row): array => [$row['territory']->name('ro') => $row['figures']])->all();
    $centru = Personas::territory('chisinau/sectorul-centru')->name('ro');
    $botanica = Personas::territory('chisinau/sectorul-botanica')->name('ro');
    $balti = Personas::territory('balti')->name('ro');

    $ana = $rows('branch_a_head');
    $mihai = $rows('chisinau_head');

    // Ana: the sector Centru — five houses in two polling districts (the archived one is not counted).
    expect($ana[$centru])->toMatchArray(['houses' => 5, 'apartments' => 57])
        ->and($ana[Field::AREA_C12])->toMatchArray(['houses' => 3, 'apartments' => 33])
        ->and($ana[Field::AREA_C15])->toMatchArray(['houses' => 2, 'apartments' => 24, 'visited' => 6])
        ->and($ana)->not->toHaveKey($botanica)->not->toHaveKey(Field::AREA_B41)->not->toHaveKey($balti)
        // Pavel: Botanica only. Mihai: both sectors of his region, not Bălți. Nicolae: Bălți only.
        ->and(array_keys($rows('branch_b_head')))->toContain($botanica)->not->toContain($centru)
        ->and($mihai[$centru]['houses'] + $mihai[$botanica]['houses'])->toBe(6)
        ->and($mihai)->not->toHaveKey($balti)
        ->and(array_keys($rows('balti_head')))->toContain($balti)->not->toContain($centru, $botanica)
        ->and(array_sum(array_column(array_filter($rows('org_head'), fn (array $f, string $name): bool => in_array($name, [$centru, $botanica, $balti], true), ARRAY_FILTER_USE_BOTH), 'houses')))->toBe(7);

    // Ion's house: every status of the contact, and attempts counted.
    expect($summary->forHouse(Field::house(Field::HOUSE_ION)))->toMatchArray([
        'apartments' => 20, 'visited' => 11, 'supporters' => 4, 'attempts' => 16, 'visited_pct' => 55, 'supporter_pct' => 20,
    ])->and(Apartment::query()->distinct()->pluck('status_code')->sort()->values()->all())
        ->toBe(['contacted', 'not_home', 'not_visited', 'opposed', 'refused', 'supporter', 'undecided']);

    $this->actingAs(Personas::user('branch_a_head'))->get('/admin/field-summary')->assertOk()->assertSee(Field::AREA_C15)->assertDontSee(Field::AREA_B41)->assertDontSee(Field::AREA_BL7);
    $this->flushSession();
    $this->actingAs(Personas::user('branch_b_head'))->get('/admin/field-summary')->assertOk()->assertSee(Field::AREA_B41)->assertDontSee(Field::AREA_C12);
    $this->get('/admin/houses/'.Field::house(Field::HOUSE_ION)->id)->assertNotFound();
});

it('keeps a personal note of an agitator from the staff', function () {
    $house = Field::house(Field::HOUSE_ION);
    $notes = fn (string $persona): array => app(FieldNotes::class)->visibleTo(Personas::user($persona), $house)->get()->pluck('body')->all();

    expect($notes('branch_a_employee_1'))->toContain(Field::NOTE_PERSONAL, Field::NOTE_TEAM)
        ->and($notes('branch_a_head'))->toContain(Field::NOTE_TEAM)->not->toContain(Field::NOTE_PERSONAL)
        ->and($notes('chisinau_head'))->toContain(Field::NOTE_TEAM)->not->toContain(Field::NOTE_PERSONAL)
        ->and($notes('org_head'))->not->toContain(Field::NOTE_PERSONAL)
        ->and($notes('super_admin'))->not->toContain(Field::NOTE_PERSONAL)
        // Another branch, another agitator: nothing at all.
        ->and($notes('branch_b_head'))->toBe([])
        ->and($notes('volunteer'))->toBe([])
        // Encrypted at rest and absent from the journal.
        ->and(DB::table('apartment_notes')->pluck('body')->implode(' '))->not->toContain('Câine')
        ->and(JournalEntry::query()->where('event_type', 'geo.visit.recorded')->get()->map(fn ($e): string => json_encode($e->getAttributes()))->implode(' '))->not->toContain('Câine');

    $this->actingAs(Personas::user('branch_a_head'))->get('/admin/houses/'.$house->id)->assertOk()->assertSee(Field::NOTE_TEAM)->assertDontSee(Field::NOTE_PERSONAL);
    $this->flushSession();
    $this->actingAs(Personas::user('branch_a_employee_1'))->get('/admin/houses/'.$house->id)->assertOk()->assertSee(Field::NOTE_PERSONAL);
});

it('does not make a second visit when the same offline operation is sent again', function () {
    $maria = Personas::user('branch_a_employee_2');
    $house = Field::house(Field::HOUSE_MARIA);
    $offline = fn (): int => Visit::query()->where('house_id', $house->id)->where('source', 'offline')->count();

    // The demo world: three operations sent in the evening and once more in the morning — three visits.
    expect($offline())->toBe(3)
        ->and(FieldOperation::query()->whereIn('operation_id', Field::OPERATIONS)->pluck('received_count', 'status')->all())->toBe(['applied' => 2])
        ->and(Field::flat(Field::HOUSE_MARIA, '4'))->toMatchArray(['status_code' => 'supporter', 'attempts' => 1])
        ->and(Field::flat(Field::HOUSE_MARIA, '5')->next_visit_on)->not->toBeNull();

    // The phone sends them a third time.
    $again = app(OfflineSync::class)->apply($maria, Field::DEVICE, collect(Field::OPERATIONS)->map(fn (string $id): array => [
        'operation_id' => $id, 'entity' => 'apartment', 'entity_id' => Field::flat(Field::HOUSE_MARIA, '4')->id, 'operation' => 'visit',
        'payload' => ['status_code' => 'opposed'], 'client_timestamp' => now()->toIso8601String(),
    ])->all());

    expect(array_column($again, 'status'))->toBe(['applied', 'applied', 'applied'])
        ->and(array_column($again, 'duplicate'))->toBe([true, true, true])
        ->and($offline())->toBe(3)
        // The stored answer comes back — whatever the repeated request now carries.
        ->and(Field::flat(Field::HOUSE_MARIA, '4'))->toMatchArray(['status_code' => 'supporter', 'attempts' => 1])
        ->and(FieldOperation::query()->where('operation_id', Field::OPERATIONS[0])->value('received_count'))->toBe(3);

    // The operation on a flat of Ion's house was refused and stays refused; somebody else cannot replay hers.
    $refused = ['operation_id' => Field::OPERATION_REFUSED, 'entity' => 'apartment', 'entity_id' => Field::flat(Field::HOUSE_ION, '12')->id,
        'operation' => 'visit', 'payload' => ['status_code' => 'supporter'], 'client_timestamp' => now()->toIso8601String()];
    expect(app(OfflineSync::class)->apply($maria, Field::DEVICE, [$refused])[0])->toMatchArray(['status' => 'rejected', 'duplicate' => true])
        ->and(app(OfflineSync::class)->apply(Personas::user('branch_a_employee_1'), 'another-phone', [$refused])[0]['status'])->toBe('rejected')
        ->and(Field::flat(Field::HOUSE_ION, '12')->attempts)->toBe(0);

    $this->actingAs($maria)->postJson('/api/v1/field/sync', ['device_id' => Field::DEVICE, 'operations' => [[
        'operation_id' => Field::OPERATIONS[2], 'entity' => 'apartment', 'entity_id' => Field::flat(Field::HOUSE_MARIA, '6')->id,
        'operation' => 'visit', 'payload' => ['status_code' => 'undecided'], 'client_timestamp' => now()->toIso8601String(),
    ]]])->assertOk()->assertJsonPath('data.0.duplicate', true);
    expect($offline())->toBe(3);
});

it('counts attempts, remembers when to come back and sets a task for it', function () {
    $history = fn (string $house, string $flat): array => Visit::query()->where('apartment_id', Field::flat($house, $flat)->id)->orderBy('visited_at')->pluck('status_code')->all();

    // Three attempts: twice nobody home, then a supporter.
    expect($history(Field::HOUSE_ION, '8'))->toBe(['not_home', 'not_home', 'supporter'])
        ->and(Field::flat(Field::HOUSE_ION, '8'))->toMatchArray(['status_code' => 'supporter', 'attempts' => 3])
        // A flat to come back to yesterday: overdue.
        ->and(Field::flat(Field::HOUSE_ION, '9')->next_visit_on->isPast())->toBeTrue()
        ->and(Field::flat(Field::HOUSE_ION, '7')->next_visit_on->isFuture())->toBeTrue();

    // "Вернуться": a task the agitator set himself — a volunteer too, who cannot create tasks otherwise.
    foreach ([['branch_a_employee_1', Field::HOUSE_ION, '7'], ['volunteer', Field::HOUSE_RADU, '3']] as [$persona, $house, $flat]) {
        $task = Task::query()->findOrFail(Visit::query()->where('apartment_id', Field::flat($house, $flat)->id)->whereNotNull('task_id')->sole()->task_id);
        expect($task->type_code)->toBe('follow_up_visit')
            ->and($task->title)->toContain($house)
            ->and($task->creator_person_id)->toBe(Personas::user($persona)->person_id)
            ->and($task->people()->where('person_id', Personas::user($persona)->person_id)->exists())->toBeTrue();
    }
    expect(Personas::user('volunteer')->can('tasks.create'))->toBeFalse();
});

it('keeps one street in the directory, however it was typed', function () {
    $chisinau = Personas::territory('chisinau')->id;

    // "str. Teilor" and "Strada Teilor" — one entry; the Cyrillic entry of the boulevard was merged into the Latin one.
    expect(Street::query()->where('territory_id', $chisinau)->orderBy('name')->pluck('name')->all())
        ->toBe(['bd. Podgorenilor', 'str. Meșterilor', 'str. Teilor', 'str. Zorilor'])
        ->and(Field::house(Field::HOUSE_UNTOUCHED)->address->street->name)->toBe('bd. Podgorenilor')
        ->and(JournalEntry::query()->where('event_type', 'geo.street.merged')->count())->toBe(1);

    $this->actingAs(Personas::user('catalog_admin'))->get('/admin/streets')->assertOk()->assertSee('bd. Podgorenilor')->assertDontSee('Подгоренилор');
    $this->flushSession();
    $this->actingAs(Personas::user('branch_a_head'))->get('/admin/streets')->assertForbidden();
});

it('assigns houses within the limit and within the scope of the head', function () {
    $assignments = app(ManageAssignments::class);
    $ana = Personas::user('branch_a_head');
    $radu = Personas::user('volunteer')->person;

    // Radu answers for two houses; a third is the limit, a fourth is refused.
    expect($assignments->assignHouse($ana, Field::house(Field::HOUSE_MARIA), $radu)->person_id)->toBe($radu->id)
        ->and(fn () => $assignments->assignHouse($ana, Field::house(Field::HOUSE_UNTOUCHED), $radu))->toThrow(GeoRuleViolation::class)
        // Pavel leads another branch; a house in the archive is not given to anybody.
        ->and(fn () => $assignments->assignHouse(Personas::user('branch_b_head'), Field::house(Field::HOUSE_ION), Personas::user('branch_b_employee_1')->person))->toThrow(AuthorizationException::class)
        ->and(fn () => $assignments->assignHouse($ana, Field::house(Field::HOUSE_ARCHIVED), Personas::user('branch_a_employee_3')->person))->toThrow(GeoRuleViolation::class);

    // The assignment is seen in the card of the person and of the house.
    $this->actingAs($ana)->get('/admin/people/'.Personas::user('branch_a_employee_1')->person_id)->assertOk()->assertSee(Field::HOUSE_ION);
    $this->get('/admin/houses/'.Field::house(Field::HOUSE_ION)->id)->assertOk()->assertSee(Personas::user('branch_a_employee_1')->person->fullName());
    expect(fieldNotices('branch_a_employee_2', 'field_assigned'))->toHaveCount(1);
});

it('binds an event to the geozone it falls into and tells those who answer for the zone', function () {
    $zone = Field::zone(Field::ZONE);
    $visible = fn (string $persona): array => app(ManageZones::class)->visibleTo(Personas::user($persona))->orderBy('name')->pluck('name')->all();

    expect(GeoZoneLink::query()->where('geo_zone_id', $zone->id)->sole()->origin)->toBe('auto')
        // Ion answers for the zone and sees the event of the sector: told. Ana holds the event herself: not told.
        ->and(fieldNotices('branch_a_employee_1', 'field_zone_event'))->toHaveCount(1)
        ->and(fieldNotices('branch_a_employee_1', 'field_zone_event')[0])->toContain(Field::EVENT_IN_ZONE)
        ->and(fieldNotices('branch_a_head', 'field_zone_event'))->toBe([])
        // Seen in the sector it stands in; managed by its head, not by the head of the next one.
        ->and($visible('branch_a_employee_2'))->toBe([Field::ZONE])
        ->and($visible('branch_b_employee_1'))->toBe([Field::ZONE_BOTANICA])
        ->and($visible('chisinau_head'))->toBe([Field::ZONE_BOTANICA, Field::ZONE])
        ->and($visible('balti_employee_2'))->toBe([])
        ->and(fn () => app(ManageZones::class)->update(Personas::user('branch_b_head'), $zone, ['name' => 'A mea']))->toThrow(AuthorizationException::class)
        // The canvass inside the outline: the three houses of str. Teilor and str. Meșterilor.
        ->and(app(CanvassSummary::class)->forZone(Personas::user('branch_a_head'), $zone))->toMatchArray(['houses' => 3, 'apartments' => 33])
        ->and(app(CanvassSummary::class)->forZone(Personas::user('branch_b_head'), $zone)['houses'])->toBe(0);

    $this->actingAs(Personas::user('branch_a_employee_2'))->get('/admin/geo-zones/'.$zone->id)->assertOk()->assertSee(Field::ZONE)->assertSee(Field::EVENT_IN_ZONE);
    $this->flushSession();
    $this->actingAs(Personas::user('branch_b_employee_1'))->get('/admin/geo-zones/'.$zone->id)->assertNotFound();
});

it('shares a location only with those who lead the person, and turns crossings into events', function () {
    $sharing = app(LocationSharing::class);
    $ion = Personas::user('branch_a_employee_1');
    $live = fn (string $persona): array => $sharing->visibleTo(Personas::user($persona))->with('person')->get()->map(fn (LocationShare $s): string => $s->person->fullName())->all();
    $name = $ion->person->fullName();

    // Ion shares now; Maria's hour ran out yesterday; Radu stopped his own sharing.
    expect(LocationShare::query()->count())->toBe(3)
        ->and($live('branch_a_employee_1'))->toBe([$name])
        ->and($live('branch_a_head'))->toBe([$name])
        ->and($live('chisinau_head'))->toBe([$name])
        ->and($live('org_head'))->toBe([$name])
        ->and($live('branch_b_head'))->toBe([])
        ->and($live('branch_a_employee_2'))->toBe([])
        ->and($live('security'))->toBe([])
        ->and(LocationShare::query()->where('person_id', Personas::user('volunteer')->person_id)->sole()->stopped_at)->not->toBeNull();

    // Crossings of the zone, in order: Radu in yesterday (he stopped sharing inside — that is not "left");
    // today the minibus in, Ion in, the minibus out.
    $zone = Field::zone(Field::ZONE);
    expect(GeoZoneCrossing::query()->where('geo_zone_id', $zone->id)->orderBy('occurred_at')->get()->map(fn ($c): string => $c->mover_type.':'.$c->direction)->all())
        ->toBe(['person:enter', 'vehicle:enter', 'person:enter', 'vehicle:exit'])
        // Ana leads Ion and Radu and manages the minibus: told about all four. Ion answers for the zone too —
        // but may see neither Radu's location nor the vehicle: told nothing.
        ->and(fieldNotices('branch_a_head', 'field_zone_crossing'))->toHaveCount(4)
        ->and(fieldNotices('branch_a_employee_1', 'field_zone_crossing'))->toBe([]);

    // A look at the track is journaled; a head of another branch gets nothing.
    $share = $sharing->current($ion);
    $viewed = JournalEntry::query()->where('event_type', 'geo.location.track_viewed')->count();
    expect($sharing->track(Personas::user('branch_a_head'), $share))->toHaveCount(3)
        ->and(JournalEntry::query()->where('event_type', 'geo.location.track_viewed')->count())->toBe($viewed + 1)
        ->and(fn () => $sharing->track(Personas::user('branch_b_head'), $share))->toThrow(AuthorizationException::class);

    // He stops whenever he likes — and is gone from every map at once.
    $this->actingAs($ion)->postJson('/api/v1/field/location/stop')->assertOk()->assertJsonPath('data.sharing', false);
    expect($live('branch_a_head'))->toBe([])->and(app(MapData::class)->people(Personas::user('branch_a_head')))->toBe([]);
});

it('shows on the map the houses of the reader\'s territories, and the vehicle to those who manage it', function () {
    $map = app(MapData::class);
    $houses = fn (string $persona): array => collect($map->houses(Personas::user($persona)))->mapWithKeys(fn (array $h): array => [$h['label'] => $h['url'] !== null])->all();

    // Ion, an employee of the sector Centru: its five houses as colours, the card of his own only.
    expect($houses('branch_a_employee_1'))->toEqualCanonicalizing([
        Field::HOUSE_ION => true, Field::HOUSE_RADU => false, Field::HOUSE_PRIVATE => false, Field::HOUSE_MARIA => false, Field::HOUSE_UNTOUCHED => false,
    ])->and(array_keys($houses('branch_b_employee_1')))->toBe([Field::HOUSE_BOTANICA])
        ->and(array_keys($houses('chisinau_head')))->toHaveCount(6)
        ->and(array_keys($houses('balti_head')))->toBe([Field::HOUSE_BALTI]);

    $vehicles = fn (string $persona): array => array_column($map->vehiclesOf(Personas::user($persona)), 'name');
    $bus = Vehicle::query()->where('name', Field::VEHICLE)->sole();
    expect($vehicles('branch_a_head'))->toBe([Field::VEHICLE])
        ->and($vehicles('chisinau_head'))->toBe([Field::VEHICLE])
        ->and($vehicles('branch_b_head'))->toBe([])
        ->and($vehicles('branch_a_employee_1'))->toBe([])
        ->and($bus->hasTracker())->toBeTrue()
        ->and(app(ManageVehicles::class)->track(Personas::user('branch_a_head'), $bus))->toHaveCount(5);

    // The tracker reports with its own key only.
    $this->postJson('/api/v1/trackers/positions', ['latitude' => 47.02, 'longitude' => 28.83], ['Authorization' => 'Bearer trk_demo'])->assertUnauthorized();
    $key = app(ManageVehicles::class)->issueKey(Personas::user('branch_a_head'), $bus);
    $this->postJson('/api/v1/trackers/positions', ['latitude' => 47.02, 'longitude' => 28.83], ['Authorization' => 'Bearer '.$key])->assertOk()->assertJsonPath('stored', 1);

    $this->actingAs(Personas::user('branch_a_employee_1'))->get('/admin/field-map')->assertOk()->assertSee('data-houses="5"', false);
    $this->get('/admin/vehicles')->assertForbidden();
    $this->get('/admin/field-settings')->assertForbidden();
});
