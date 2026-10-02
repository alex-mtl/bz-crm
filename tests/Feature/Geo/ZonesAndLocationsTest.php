<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Events\Actions\ManageEvents;
use App\Domain\Events\Models\Event;
use App\Domain\Geo\Actions\LocationSharing;
use App\Domain\Geo\Actions\ManageVehicles;
use App\Domain\Geo\Actions\ManageZones;
use App\Domain\Geo\CanvassSummary;
use App\Domain\Geo\Events\GeoZoneCrossed;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\GeoService;
use App\Domain\Geo\MapData;
use App\Domain\Geo\Models\GeoZone;
use App\Domain\Geo\Models\GeoZoneCrossing;
use App\Domain\Geo\Models\GeoZoneLink;
use App\Domain\Geo\Models\LocationPoint;
use App\Domain\Geo\Models\LocationShare;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event as EventBus;
use Tests\Support\FieldFixture;

/*
 * ФО §6.11, Д-22 — geozones with their bindings, voluntary location sharing, vehicles with trackers, the map.
 */

beforeEach(function () {
    $this->field = FieldFixture::build();
    $this->org = $this->field->org;
    // A square around houseA (47.02, 28.84), about 1 km wide.
    $this->square = fn (float $lat = 47.02, float $lng = 28.84, float $half = 0.005): array => [
        [$lat + $half, $lng - $half], [$lat + $half, $lng + $half], [$lat - $half, $lng + $half], [$lat - $half, $lng - $half],
    ];
    $this->zone = fn ($by, array $data = []): GeoZone => app(ManageZones::class)->create($by, [
        'name' => 'Centru — piața', 'territory_id' => $this->org->centru->id, 'corners' => ($this->square)(), ...$data,
    ]);
    $this->zoneIds = fn ($user): array => app(ManageZones::class)->visibleTo($user)->orderBy('geo_zones.id')->pluck('geo_zones.id')->all();
    $this->notices = fn ($user, string $kind): int => $user->notifications()->where('data->kind', $kind)->count();
});

it('answers every spatial question in one place', function () {
    $geo = app(GeoService::class);
    $outline = $geo->outline(($this->square)());
    $withHole = ['type' => 'Polygon', 'coordinates' => [
        $outline['geometry']['coordinates'][0],
        [[28.839, 47.019], [28.841, 47.019], [28.841, 47.021], [28.839, 47.021], [28.839, 47.019]],
    ]];

    expect($outline['geometry']['type'])->toBe('Polygon')
        ->and($outline['geometry']['coordinates'][0])->toHaveCount(5)
        ->and($outline['box'])->toMatchArray(['min_latitude' => 47.015, 'max_latitude' => 47.025])
        ->and($geo->contains($outline['geometry'], 47.02, 28.84))->toBeTrue()
        ->and($geo->contains($outline['geometry'], 47.03, 28.84))->toBeFalse()
        ->and($geo->contains($outline['geometry'], 47.02, 28.85))->toBeFalse()
        ->and($geo->contains($withHole, 47.02, 28.84))->toBeFalse()                 // inside the hole
        ->and($geo->contains($withHole, 47.017, 28.837))->toBeTrue()
        ->and($geo->contains(['type' => 'MultiPolygon', 'coordinates' => [$outline['geometry']['coordinates']]], 47.02, 28.84))->toBeTrue()
        ->and(round($geo->distance(47.02, 28.84, 47.03, 28.84)))->toBeBetween(1100.0, 1120.0)
        ->and(fn () => $geo->outline([[47.0, 28.8], [47.1, 28.9]]))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $geo->outline([[47.0, 28.8], [47.0, 28.8], [47.0, 28.8]]))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $geo->outline([[97.0, 28.8], [47.1, 28.9], [47.2, 28.7]]))->toThrow(GeoRuleViolation::class);
});

it('keeps a geozone as an object managed where it stands', function () {
    $o = $this->org;
    $zone = ($this->zone)($o->headA, ['responsible_ids' => [$o->a1->person_id]]);
    $whole = ($this->zone)($o->orgHead, ['name' => 'Toată țara', 'territory_id' => null, 'corners' => ($this->square)(47.5, 28.5, 1.0)]);
    FieldFixture::forget();

    expect($zone->responsibles()->pluck('people.id')->all())->toBe([$o->a1->person_id])
        ->and(app(GeoService::class)->zoneCorners($zone))->toHaveCount(4)
        ->and(fn () => ($this->zone)($o->headB))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->zone)($o->a1))->toThrow(AuthorizationException::class)
        // A zone of the whole organization — only by those whose role covers the whole organization.
        ->and(fn () => ($this->zone)($o->headA, ['territory_id' => null]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManageZones::class)->update($o->headB, $zone, ['name' => 'X']))->toThrow(AuthorizationException::class)
        // Seen where its territory touches the reader's territories, and by those who answer for it.
        ->and(($this->zoneIds)($o->headA))->toBe([$zone->id, $whole->id])
        ->and(($this->zoneIds)($o->regionHead))->toBe([$zone->id, $whole->id])
        ->and(($this->zoneIds)($o->a2))->toBe([$zone->id, $whole->id])
        ->and(($this->zoneIds)($o->b1))->toBe([$whole->id])
        ->and(($this->zoneIds)($o->balti1))->toBe([$whole->id])
        ->and(($this->zoneIds)($o->orgHead))->toBe([$zone->id, $whole->id])
        ->and(($this->zoneIds)(userWithRoles('candidate')))->toBe([]);

    app(ManageZones::class)->update($o->headA, $zone, ['name' => 'Centru — piața centrală', 'corners' => ($this->square)(47.02, 28.84, 0.01)]);
    expect($zone->fresh()->name)->toBe('Centru — piața centrală')
        ->and((float) $zone->fresh()->max_latitude)->toBe(47.03)
        ->and(journalCount('geo.zone.updated'))->toBe(1);

    app(ManageZones::class)->archive($o->headA, $zone);
    expect(app(GeoService::class)->zonesAt(47.02, 28.84)->pluck('id')->all())->toBe([$whole->id]);
});

it('binds an event to the geozones its point stands in, and tells those who answer for them', function () {
    $o = $this->org;
    $zone = ($this->zone)($o->headA, ['responsible_ids' => [$o->a1->person_id, $o->b1->person_id, $o->headA->person_id]]);
    $events = app(ManageEvents::class);
    $data = ['type_code' => 'meeting', 'starts_at' => now()->addDays(2), 'latitude' => 47.021, 'longitude' => 28.841];

    // A private event of the head of branch A: a1 is not invited and does not see it; b1 never would.
    $private = $events->create($o->headA, ['title' => 'Întâlnire închisă', 'visibility' => Event::PRIVATE, ...$data]);
    $public = $events->create($o->orgHead, ['title' => 'Întâlnire cu locatarii', 'visibility' => Event::PUBLIC, ...$data]);
    $outside = $events->create($o->orgHead, ['title' => 'Departe', 'visibility' => Event::PUBLIC, ...$data, 'latitude' => 47.2]);

    $bound = fn (): array => GeoZoneLink::query()->where('geo_zone_id', $zone->id)->orderBy('subject_id')->pluck('subject_id')->all();
    expect($bound())->toBe([$private->id, $public->id])
        // Told about the public one; about the private one — nobody who does not see it, and not its own organizer.
        ->and(($this->notices)($o->a1, 'field_zone_event'))->toBe(1)
        ->and(($this->notices)($o->b1, 'field_zone_event'))->toBe(1)
        ->and(($this->notices)($o->headA, 'field_zone_event'))->toBe(1)
        ->and($o->a1->notifications()->get()->pluck('data')->map(fn ($d) => json_encode($d, JSON_UNESCAPED_UNICODE))->implode(' '))->not->toContain('Întâlnire închisă');

    // An event for the sector: bound when its audience is already written — so a1 (Centru) is told, b1 (Botanica) is not.
    $events->create($o->headA, ['title' => 'Pentru sector', 'visibility' => Event::REGIONAL, 'territory_ids' => [$o->centru->id], ...$data]);
    expect(($this->notices)($o->a1, 'field_zone_event'))->toBe(2)->and(($this->notices)($o->b1, 'field_zone_event'))->toBe(1);
    GeoZoneLink::query()->where('subject_id', Event::query()->where('title', 'Pentru sector')->value('id'))->delete();

    // Moved out of the zone — unbound; a manual binding stays wherever the point is.
    $events->update($o->orgHead, $public, ['latitude' => 47.3, 'longitude' => 28.9]);
    app(ManageZones::class)->linkEvent($o->headA, $zone, $outside);
    expect($bound())->toBe([$private->id, $outside->id])
        ->and(GeoZoneLink::query()->where('subject_id', $outside->id)->value('origin'))->toBe('manual')
        // Binding by hand takes the right to the zone and the sight of the event.
        ->and(fn () => app(ManageZones::class)->linkEvent($o->headB, $zone, $outside))->toThrow(AuthorizationException::class);

    // A zone redrawn over a coming event binds it.
    $second = ($this->zone)($o->headA, ['name' => 'A doua', 'corners' => ($this->square)(47.3, 28.9)]);
    expect(GeoZoneLink::query()->where('geo_zone_id', $second->id)->pluck('subject_id')->all())->toBe([$public->id]);
});

it('counts the canvass inside a geozone', function () {
    $o = $this->org;
    $f = $this->field;
    $zone = ($this->zone)($o->headA, ['corners' => ($this->square)(47.025, 28.835, 0.01)]);   // covers houseA and houseA2
    $f->visit($o->a1, $f->houseA, '1', 'supporter');
    $f->visit($f->volunteer, $f->houseA2, '1', 'opposed');

    expect(app(CanvassSummary::class)->forZone($o->headA, $zone))->toMatchArray(['houses' => 2, 'apartments' => 18, 'visited' => 2, 'supporters' => 1])
        // Counted over the houses the reader's right reaches: the head of branch B gets nothing of Centru.
        ->and(app(CanvassSummary::class)->forZone($o->headB, $zone))->toMatchArray(['houses' => 0, 'apartments' => 0]);
});

it('shares a location only by the will of the person, for a time, to those who lead them', function () {
    $o = $this->org;
    $sharing = app(LocationSharing::class);

    expect(fn () => $sharing->report($o->a1, 47.02, 28.84))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $sharing->start($o->a1, 37))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $sharing->start(userWithRoles('candidate'), 60))->toThrow(AuthorizationException::class);

    $share = $sharing->start($o->a1, 60);
    $sharing->report($o->a1, 47.02, 28.84, 12);
    $sharing->report($o->a1, 47.021, 28.841, 9);
    $visible = fn ($viewer): array => $sharing->visibleTo($viewer)->pluck('location_shares.person_id')->all();

    expect($share->fresh())->toMatchArray(['last_latitude' => '47.021000', 'last_accuracy' => 9])
        ->and(LocationPoint::query()->where('location_share_id', $share->id)->count())->toBe(2)
        ->and($visible($o->a1))->toBe([$o->a1->person_id])
        ->and($visible($o->headA))->toBe([$o->a1->person_id])
        ->and($visible($o->regionHead))->toBe([$o->a1->person_id])
        ->and($visible($o->headB))->toBe([])
        ->and($visible($o->a2))->toBe([])
        ->and(journalCount('geo.location.sharing_started'))->toBe(1);

    // A look at somebody's track is journaled; one's own is not; a stranger gets nothing.
    expect($sharing->track($o->headA, $share))->toHaveCount(2)
        ->and($sharing->track($o->a1, $share))->toHaveCount(2)
        ->and(journalCount('geo.location.track_viewed'))->toBe(1)
        ->and(fn () => $sharing->track($o->headB, $share))->toThrow(AuthorizationException::class);

    // Stopped at any moment.
    $sharing->stop($o->a1);
    expect($visible($o->headA))->toBe([])
        ->and(fn () => $sharing->report($o->a1, 47.02, 28.84))->toThrow(GeoRuleViolation::class)
        ->and(journalCount('geo.location.sharing_stopped'))->toBe(1);

    // Runs out by itself.
    $sharing->start($o->a1, 60);
    $this->travel(61)->minutes();
    expect($visible($o->headA))->toBe([])->and(fn () => $sharing->report($o->a1, 47.02, 28.84))->toThrow(GeoRuleViolation::class);

    // Kept for a limited number of days.
    $this->travel(app(FieldSettings::class)->locationRetentionDays() + 1)->days();
    expect($sharing->purge())->toBe(2)
        ->and(LocationShare::query()->count())->toBe(0)
        ->and(journalCount('geo.location.purged'))->toBe(1);
});

it('turns crossing the border of a geozone into an event', function () {
    $o = $this->org;
    EventBus::fake([GeoZoneCrossed::class]);
    $zone = ($this->zone)($o->headA, ['responsible_ids' => [$o->headA->person_id, $o->headB->person_id]]);
    $sharing = app(LocationSharing::class);
    $sharing->start($o->a1, 240);

    $sharing->report($o->a1, 47.10, 28.84);        // outside
    $sharing->report($o->a1, 47.02, 28.84);        // enters
    $sharing->report($o->a1, 47.021, 28.841);      // still inside — not an event
    $sharing->report($o->a1, 47.10, 28.84);        // leaves

    expect(GeoZoneCrossing::query()->where('geo_zone_id', $zone->id)->orderBy('id')->pluck('direction')->all())->toBe(['enter', 'exit'])
        ->and(GeoZoneCrossing::query()->first())->toMatchArray(['mover_type' => 'person', 'mover_id' => $o->a1->person_id]);
    EventBus::assertDispatchedTimes(GeoZoneCrossed::class, 2);

    // Told: the head who may see where a1 is. Not told: the head of another branch, though responsible for the zone.
    expect(($this->notices)($o->headA, 'field_zone_crossing'))->toBe(2)
        ->and(($this->notices)($o->headB, 'field_zone_crossing'))->toBe(0);

    // Stopping the sharing is not "leaving".
    $sharing->report($o->a1, 47.02, 28.84);
    $sharing->stop($o->a1);
    expect(GeoZoneCrossing::query()->count())->toBe(3)
        ->and(DB::table('geo_zone_presences')->count())->toBe(0);
});

it('keeps vehicles with trackers that report by a key of their own', function () {
    $o = $this->org;
    $vehicles = app(ManageVehicles::class);
    $zone = ($this->zone)($o->headA, ['responsible_ids' => [$o->headA->person_id]]);
    $bus = $vehicles->create($o->headA, ['name' => 'Microbuzul filialei', 'plate' => 'c ab 123', 'type_code' => 'minibus', 'org_unit_id' => $o->branchA->id]);

    expect($bus->plate)->toBe('C AB 123')
        ->and(fn () => $vehicles->create($o->headA, ['name' => 'Al altora', 'type_code' => 'car', 'org_unit_id' => $o->branchB->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => $vehicles->create($o->a1, ['name' => 'Al meu', 'type_code' => 'car', 'org_unit_id' => $o->branchA->id]))->toThrow(AuthorizationException::class)
        ->and($vehicles->visibleTo($o->headA)->pluck('id')->all())->toBe([$bus->id])
        ->and($vehicles->visibleTo($o->regionHead)->pluck('id')->all())->toBe([$bus->id])
        ->and($vehicles->visibleTo($o->headB)->pluck('id')->all())->toBe([])
        ->and($vehicles->visibleTo($o->a1)->pluck('id')->all())->toBe([]);

    $key = $vehicles->issueKey($o->headA, $bus);
    $at = now()->subMinutes(3)->toIso8601String();

    expect($key)->toStartWith('trk_')
        ->and($bus->fresh()->tracker_key_hash)->toBe(hash('sha256', $key))->not->toBe($key)
        ->and($vehicles->report('trk_wrong', [['latitude' => 47.02, 'longitude' => 28.84]]))->toBeNull()
        ->and($vehicles->report($key, [
            ['latitude' => 47.10, 'longitude' => 28.84, 'recorded_at' => now()->subMinutes(5)->toIso8601String()],
            ['latitude' => 47.02, 'longitude' => 28.84, 'recorded_at' => $at],
            ['latitude' => 999, 'longitude' => 28.84],
        ]))->toBe(2)
        // The same batch again: nothing new.
        ->and($vehicles->report($key, [['latitude' => 47.02, 'longitude' => 28.84, 'recorded_at' => $at]]))->toBe(0)
        ->and($bus->fresh()->last_latitude)->toBe('47.020000')
        ->and(GeoZoneCrossing::query()->where('geo_zone_id', $zone->id)->where('mover_type', 'vehicle')->pluck('direction')->all())->toBe(['enter'])
        ->and(($this->notices)($o->headA, 'field_zone_crossing'))->toBe(1)
        ->and($vehicles->track($o->headA, $bus))->toHaveCount(2)
        ->and(fn () => $vehicles->track($o->headB, $bus))->toThrow(AuthorizationException::class)
        ->and(journalCount('geo.vehicle.tracker_key_issued'))->toBe(1);

    // A new key kills the old one; an archived vehicle has no key at all.
    $second = $vehicles->issueKey($o->headA, $bus);
    expect($vehicles->report($key, [['latitude' => 47.0, 'longitude' => 28.8]]))->toBeNull();
    $vehicles->archive($o->headA, $bus);
    expect($vehicles->report($second, [['latitude' => 47.0, 'longitude' => 28.8]]))->toBeNull();
});

it('shows on the map what each reader may see', function () {
    $o = $this->org;
    $f = $this->field;
    $map = app(MapData::class);
    $zone = ($this->zone)($o->headA);
    app(LocationSharing::class)->start($o->a1, 60);
    app(LocationSharing::class)->report($o->a1, 47.02, 28.84);
    $f->visit($o->a1, $f->houseA, '1', 'supporter');
    FieldFixture::forget();
    $houses = fn ($reader): array => collect($map->houses($reader))->mapWithKeys(fn (array $house): array => [$house['id'] => $house['url']])->all();

    // An employee: the houses of their own territories as colours; the card — only of the houses they answer for.
    expect($houses($o->a2))->toBe([$f->houseA->id => null, $f->houseA2->id => null])
        ->and($houses($o->a1))->toBe([$f->houseA->id => '/admin/houses/'.$f->houseA->id, $f->houseA2->id => null])
        ->and(array_keys($houses($o->b1)))->toBe([$f->houseB->id])
        ->and(array_keys($houses($o->headA)))->toBe([$f->houseA->id, $f->houseA2->id])
        ->and(array_keys($houses($o->orgHead)))->toHaveCount(4)
        ->and($houses(userWithRoles('candidate')))->toBe([])
        ->and($map->houses($o->headA)[0])->toMatchArray(['apartments' => 12, 'visited_pct' => 8])
        ->and(array_column($map->zones($o->a2), 'id'))->toBe([$zone->id])
        ->and($map->zones($o->b1))->toBe([])
        ->and(array_column($map->people($o->headA), 'name'))->toBe([$o->a1->person->fullName()])
        ->and($map->people($o->headB))->toBe([])
        ->and($map->people($o->a1)[0]['own'])->toBeTrue()
        ->and($map->for($o->headA)['tiles']['url'])->toContain('openstreetmap');
});

it('lets the administrator tune the field work', function () {
    $o = $this->org;
    $settings = app(FieldSettings::class);

    expect($settings->housesPerAgitator())->toBe(3)
        ->and($settings->shareMinutes())->toBe([60, 240, 480])
        ->and(fn () => $settings->update($o->orgHead, ['houses_per_agitator' => 5]))->toThrow(AuthorizationException::class)
        ->and(fn () => $settings->update($o->admin, ['map_provider' => 'custom', 'map_url' => 'http://tiles.example/{z}/{x}/{y}.png']))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $settings->update($o->admin, ['location_retention_days' => 0]))->toThrow(GeoRuleViolation::class);

    $settings->update($o->admin, [
        'houses_per_agitator' => 5, 'share_minutes' => ['30', '120'], 'default_note_visibility' => 'personal',
        'map_provider' => 'custom', 'map_url' => 'https://tiles.example/{z}/{x}/{y}.png', 'map_attribution' => '© Example',
    ]);

    expect($settings->housesPerAgitator())->toBe(5)
        ->and($settings->shareMinutes())->toBe([30, 120])
        ->and($settings->defaultNoteVisibility())->toBe('personal')
        ->and($settings->map())->toMatchArray(['url' => 'https://tiles.example/{z}/{x}/{y}.png', 'attribution' => '© Example'])
        ->and(journalCount('geo.settings.changed'))->toBe(1)
        ->and(app(AuthorizationService::class)->can($o->a1, 'geo.locations.share'))->toBeTrue();
});
