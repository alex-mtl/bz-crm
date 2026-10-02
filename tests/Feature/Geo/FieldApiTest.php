<?php

use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\Models\LocationPoint;
use App\Domain\Geo\Models\LocationShare;
use App\Domain\Geo\Models\Visit;
use Illuminate\Support\Str;
use Tests\Support\FieldFixture;

/*
 * ТЗ §33–34, ADR-013 — the API of the agitator's phone, the shell of the application, the endpoint of trackers.
 */

beforeEach(function () {
    $this->field = FieldFixture::build();
    $this->org = $this->field->org;
});

it('gives the phone its houses and takes its queue', function () {
    $o = $this->org;
    $f = $this->field;
    $flat = $f->flat($f->houseA, '2');
    $operation = [
        'operation_id' => (string) Str::uuid(), 'entity' => 'apartment', 'entity_id' => $flat->id, 'operation' => 'visit',
        'payload' => ['status_code' => 'undecided', 'note' => 'Întreabă de program'], 'client_timestamp' => now()->subMinute()->toIso8601String(),
    ];

    $this->getJson('/api/v1/field/snapshot')->assertUnauthorized();
    $this->postJson('/api/v1/field/sync', ['device_id' => 'd', 'operations' => [$operation]])->assertUnauthorized();

    $this->actingAs($o->a1);
    $this->getJson('/api/v1/field/session')->assertOk()->assertJsonPath('user_id', $o->a1->id)->assertJsonStructure(['csrf']);
    $this->getJson('/api/v1/field/snapshot')->assertOk()
        ->assertJsonCount(1, 'data.houses')->assertJsonPath('data.houses.0.id', $f->houseA->id)
        ->assertJsonPath('data.houses.0.label', 'str. Ismail 12')->assertJsonCount(12, 'data.houses.0.apartments');

    $this->postJson('/api/v1/field/sync', ['device_id' => 'phone-1', 'operations' => [$operation]])->assertOk()
        ->assertJsonPath('data.0.status', 'applied')->assertJsonPath('data.0.duplicate', false)
        ->assertJsonPath('data.0.result.apartment.status', 'undecided');
    $this->postJson('/api/v1/field/sync', ['device_id' => 'phone-1', 'operations' => [$operation]])->assertOk()
        ->assertJsonPath('data.0.status', 'applied')->assertJsonPath('data.0.duplicate', true);
    $this->postJson('/api/v1/field/sync', ['operations' => [$operation]])->assertUnprocessable();

    expect(Visit::query()->where('apartment_id', $flat->id)->count())->toBe(1)
        ->and($flat->fresh()->status_code)->toBe('undecided');

    // Somebody who records no visits has no phone API.
    $this->flushSession();
    $this->actingAs(userWithRoles('candidate'))->getJson('/api/v1/field/snapshot')->assertForbidden();
});

it('opens the application to those who work in the field, and keeps its shell cacheable', function () {
    $this->get('/field')->assertRedirect();

    $this->actingAs($this->org->a1)->get('/field')->assertOk()
        ->assertSee('/field/app.js', false)->assertSee('/field/manifest.json', false)->assertSee('"may_visit":true', false)
        ->assertSee(__('geo.app.loading'));
    $this->flushSession();
    $this->actingAs(userWithRoles('candidate'))->get('/field')->assertForbidden();

    // The files the service worker keeps are there, and the worker itself is served from the root to cover /field.
    foreach (['field/app.js', 'field/app.css', 'field/manifest.json', 'field/icon.svg', 'field-sw.js'] as $file) {
        expect(file_exists(public_path($file)))->toBeTrue();
    }
    expect(json_decode((string) file_get_contents(public_path('field/manifest.json')), true))->toMatchArray(['start_url' => '/field', 'scope' => '/field', 'display' => 'standalone'])
        // Every string the application shows exists in every language.
        ->and(array_keys(trans('geo.app', [], 'ro')))->toEqualCanonicalizing(array_keys(trans('geo.app', [], 'ru')))
        ->and(array_keys(trans('geo.app', [], 'en')))->toEqualCanonicalizing(array_keys(trans('geo.app', [], 'ru')))
        ->and(array_keys(trans('geo.ui', [], 'ro')))->toEqualCanonicalizing(array_keys(trans('geo.ui', [], 'ru')))
        ->and(array_keys(trans('geo.ui', [], 'en')))->toEqualCanonicalizing(array_keys(trans('geo.ui', [], 'ru')))
        ->and(array_keys(trans('geo.errors', [], 'ro')))->toEqualCanonicalizing(array_keys(trans('geo.errors', [], 'en')));
});

it('shares a location through the phone only', function () {
    $o = $this->org;
    $this->actingAs($o->a1);

    $this->getJson('/api/v1/field/location')->assertOk()->assertJsonPath('data.sharing', false)->assertJsonPath('data.may_share', true)
        ->assertJsonPath('data.allowed_minutes', [60, 240, 480]);
    $this->postJson('/api/v1/field/location', ['latitude' => 47.02, 'longitude' => 28.84])->assertUnprocessable();
    $this->postJson('/api/v1/field/location/start', ['minutes' => 17])->assertUnprocessable();
    $this->postJson('/api/v1/field/location/start', ['minutes' => 60])->assertOk()->assertJsonPath('data.sharing', true);
    $this->postJson('/api/v1/field/location', ['latitude' => 47.02, 'longitude' => 28.84, 'accuracy' => 11.6])->assertOk();
    $this->postJson('/api/v1/field/location', ['latitude' => 147.02, 'longitude' => 28.84])->assertUnprocessable();

    expect(LocationPoint::query()->count())->toBe(1)
        ->and(LocationShare::query()->sole())->toMatchArray(['person_id' => $o->a1->person_id, 'last_accuracy' => 12]);

    $this->postJson('/api/v1/field/location/stop')->assertOk()->assertJsonPath('data.sharing', false);
    $this->postJson('/api/v1/field/location', ['latitude' => 47.02, 'longitude' => 28.84])->assertUnprocessable();
});

it('takes the points of a tracker by its key, without any session', function () {
    $o = $this->org;
    $vehicles = app(ManageVehicles::class);
    $car = $vehicles->create($o->headA, ['name' => 'Duster', 'type_code' => 'car', 'org_unit_id' => $o->branchA->id]);
    $key = $vehicles->issueKey($o->headA, $car);

    $this->postJson('/api/v1/trackers/positions', ['latitude' => 47.02, 'longitude' => 28.84])->assertUnauthorized();
    $this->postJson('/api/v1/trackers/positions', ['latitude' => 47.02, 'longitude' => 28.84], ['Authorization' => 'Bearer trk_nope'])->assertUnauthorized();

    $this->postJson('/api/v1/trackers/positions', ['latitude' => 47.02, 'longitude' => 28.84], ['Authorization' => 'Bearer '.$key])
        ->assertOk()->assertJsonPath('stored', 1);
    $this->postJson('/api/v1/trackers/positions', ['points' => [
        ['latitude' => 47.03, 'longitude' => 28.85, 'recorded_at' => now()->subMinutes(2)->toIso8601String()],
        ['latitude' => 47.04, 'longitude' => 28.86, 'recorded_at' => now()->subMinute()->toIso8601String()],
    ]], ['Authorization' => 'Bearer '.$key])->assertOk()->assertJsonPath('stored', 2);

    expect(LocationPoint::query()->where('vehicle_id', $car->id)->count())->toBe(3)
        ->and($car->fresh()->last_point_at)->not->toBeNull();
});
