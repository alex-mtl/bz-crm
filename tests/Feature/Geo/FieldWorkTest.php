<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Geo\Actions\ManageAddresses;
use App\Domain\Geo\Actions\ManageAssignments;
use App\Domain\Geo\Actions\ManageHouses;
use App\Domain\Geo\Actions\OfflineSync;
use App\Domain\Geo\AddressNormalizer;
use App\Domain\Geo\CanvassSummary;
use App\Domain\Geo\Exceptions\GeoRuleViolation;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\FieldNotes;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\FieldSnapshot;
use App\Domain\Geo\Models\ApartmentNote;
use App\Domain\Geo\Models\FieldAssignment;
use App\Domain\Geo\Models\FieldOperation;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Street;
use App\Domain\Geo\Models\Visit;
use App\Domain\Tasks\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Tests\Support\FieldFixture;

/*
 * ФО §6.11, ТЗ §31–34, catalog §9 — houses and flats, who answers for them, visits and notes, the offline queue,
 * the summaries.
 */

beforeEach(function () {
    $this->field = FieldFixture::build();
    $this->org = $this->field->org;
    $this->houses = fn ($user, string $code = 'geo.houses.read'): array => app(FieldAccess::class)->houses($user, $code)->orderBy('houses.id')->pluck('houses.id')->all();
    $this->operation = fn (int $apartmentId, array $payload = [], array $extra = []): array => [
        'operation_id' => (string) Str::uuid(), 'entity' => 'apartment', 'entity_id' => $apartmentId, 'operation' => 'visit',
        'payload' => ['status_code' => 'supporter', ...$payload], 'client_timestamp' => now()->subMinutes(5)->toIso8601String(), ...$extra,
    ];
});

it('keeps one entry for a street however its name is typed', function () {
    $normalizer = new AddressNormalizer;

    expect($normalizer->street('str. Ismail'))->toMatchArray(['type' => 'street', 'key' => 'ismail'])
        ->and($normalizer->street('Strada  ISMAIL'))->toMatchArray(['type' => 'street', 'key' => 'ismail'])
        ->and($normalizer->street('Ismail strada'))->toMatchArray(['type' => 'street', 'key' => 'ismail'])
        ->and($normalizer->street('ул. Ленина')['key'])->toBe($normalizer->street('улица Ленина')['key'])
        ->and($normalizer->street('Ленина ул.')['key'])->toBe('ленина')
        ->and($normalizer->street('bd. Ștefan cel Mare'))->toMatchArray(['type' => 'boulevard', 'key' => 'stefan cel mare'])
        ->and($normalizer->street('Bulevardul Stefan cel Mare')['key'])->toBe('stefan cel mare')
        ->and($normalizer->street('Ismail')['type'])->toBe('street')
        ->and($normalizer->number(' nr. 12 a '))->toBe('12A')
        ->and($normalizer->number('12/1'))->toBe('12/1');

    $addresses = app(ManageAddresses::class);
    $chisinau = $this->org->chisinau;
    $street = $addresses->street($chisinau, 'strada Ismail');

    // The fixture typed "str. Ismail" — the same row, spelled as the directory spells it.
    expect($street->id)->toBe($this->field->houseA->address->street_id)
        ->and($street->name)->toBe('str. Ismail')
        ->and(Street::query()->where('normalized', 'ismail')->count())->toBe(1)
        ->and($addresses->address($street, '12 ')->id)->toBe($this->field->houseA->address_id)
        // A street is kept under the settlement, not under the polling district of the house.
        ->and($street->territory_id)->toBe($chisinau->id)
        // Another kind of street with the same name is another street.
        ->and($addresses->street($chisinau, 'bd. Ismail')->id)->not->toBe($street->id);
});

it('lets the keepers of the directory rename and merge streets', function () {
    $addresses = app(ManageAddresses::class);
    $o = $this->org;
    $cyrillic = $addresses->street($o->chisinau, 'ул. Измаил');
    $addresses->address($cyrillic, '14');
    $taken = $addresses->address($cyrillic, '12');
    $latin = $this->field->houseA->address->street;

    expect(fn () => $addresses->merge($o->headA, $cyrillic, $latin))->toThrow(AuthorizationException::class)
        ->and(fn () => $addresses->rename($o->admin, $cyrillic, 'str. Ismail'))->toThrow(GeoRuleViolation::class);

    $addresses->merge($o->admin, $cyrillic, $latin);

    expect(Street::query()->whereKey($cyrillic->id)->exists())->toBeFalse()
        ->and($latin->addresses()->pluck('normalized_number')->sort()->values()->all())->toBe(['12', '14'])
        ->and($this->field->houseA->fresh()->address_id)->not->toBe($taken->id)
        ->and(journalCount('geo.street.merged'))->toBe(1);

    $addresses->rename($o->admin, $latin, 'str. Ismail (veche)');
    expect($latin->fresh()->name)->toBe('str. Ismail (veche)')->and(journalCount('geo.street.renamed'))->toBe(1);
});

it('adds a house where the head manages houses, with its flats', function () {
    $o = $this->org;
    $houses = app(ManageHouses::class);
    $house = $this->field->houseA;

    expect($house->apartments()->count())->toBe(12)
        ->and($house->apartments()->pluck('entrance')->unique()->values()->all())->toBe([1, 2])
        ->and($this->field->flat($house, '1')->floor)->toBe(1)
        ->and($this->field->flat($house, '6')->floor)->toBe(3)
        ->and($this->field->flat($house, '7')->entrance)->toBe(2)
        ->and($house->label())->toBe('str. Ismail 12')
        ->and(journalCount('geo.house.created'))->toBe(4);

    // A private house is one household.
    $private = $houses->create($o->headA, ['territory_id' => $this->field->c1->id, 'street' => 'str. Ismail', 'number' => '14', 'type_code' => House::PRIVATE_HOUSE, 'apartments' => 9]);
    expect($private->apartments()->count())->toBe(1);

    expect(fn () => $houses->create($o->headA, ['territory_id' => $this->field->c1->id, 'street' => 'Strada Ismail', 'number' => '12']))->toThrow(GeoRuleViolation::class)
        // Not in a territory of another branch, and not by an agitator.
        ->and(fn () => $houses->create($o->headA, ['territory_id' => $this->field->b1Area->id, 'street' => 'str. Nouă', 'number' => '1']))->toThrow(AuthorizationException::class)
        ->and(fn () => $houses->create($o->a1, ['territory_id' => $this->field->c1->id, 'street' => 'str. Nouă', 'number' => '1']))->toThrow(AuthorizationException::class)
        ->and(fn () => $houses->update($o->headB, $house, ['floors' => 9]))->toThrow(AuthorizationException::class);

    $houses->update($o->regionHead, $house, ['floors' => 5, 'description' => 'Interfon']);
    expect($house->fresh()->floors)->toBe(5)
        ->and($houses->addApartments($o->headA, $house, 11, 14))->toBe(2)
        ->and(fn () => $houses->update($o->headA, $house, ['territory_id' => $this->field->b1Area->id]))->toThrow(AuthorizationException::class);

    $houses->archive($o->headA, $house);
    expect(fn () => $this->field->visit($o->a1, $house->fresh(), '1', 'supporter'))->toThrow(GeoRuleViolation::class);
});

it('opens a house to the heads of its territory and to the agitators who answer for it', function () {
    $o = $this->org;
    $f = $this->field;
    $all = [$f->houseA->id, $f->houseA2->id, $f->houseB->id, $f->houseBalti->id];

    expect(($this->houses)($o->admin))->toBe($all)
        ->and(($this->houses)($o->orgHead))->toBe($all)
        ->and(($this->houses)($o->regionHead))->toBe([$f->houseA->id, $f->houseA2->id, $f->houseB->id])
        ->and(($this->houses)($o->headA))->toBe([$f->houseA->id, $f->houseA2->id])
        ->and(($this->houses)($o->headB))->toBe([$f->houseB->id])
        ->and(($this->houses)($o->baltiHead))->toBe([$f->houseBalti->id])
        // An agitator: only the houses they answer for.
        ->and(($this->houses)($o->a1))->toBe([$f->houseA->id])
        ->and(($this->houses)($f->volunteer))->toBe([$f->houseA2->id])
        ->and(($this->houses)($o->a2))->toBe([])
        ->and(($this->houses)($o->central1))->toBe([])
        ->and(app(FieldAccess::class)->can($o->a1, 'geo.houses.read', $f->houseA))->toBeTrue()
        ->and(app(FieldAccess::class)->can($o->a1, 'geo.houses.read', $f->houseA2))->toBeFalse()
        ->and(app(FieldAccess::class)->can($o->a1, 'geo.houses.manage', $f->houseA))->toBeFalse()
        // A head records visits only in a house of their own, like anybody else.
        ->and(app(FieldAccess::class)->can($o->headA, 'geo.visits.create', $f->houseA))->toBeFalse()
        ->and(($this->houses)($o->a1, 'geo.visits.create'))->toBe([$f->houseA->id]);

    // A territory given as a whole covers a house added to it later.
    $later = app(ManageHouses::class)->create($o->headA, ['territory_id' => $f->c2->id, 'street' => 'str. Pușkin', 'number' => '3', 'apartments' => 2]);
    FieldFixture::forget();
    expect(($this->houses)($f->volunteer))->toBe([$f->houseA2->id, $later->id]);

    // Taking the assignment away takes the house away.
    app(ManageAssignments::class)->end($o->headA, FieldAssignment::query()->where('person_id', $o->a1->person_id)->sole());
    expect(($this->houses)($o->a1))->toBe([])
        ->and(fn () => $f->visit($o->a1, $f->houseA, '1', 'supporter'))->toThrow(AuthorizationException::class)
        ->and(journalCount('geo.assignment.ended'))->toBe(1);
});

it('assigns agitators within the rules', function () {
    $o = $this->org;
    $f = $this->field;
    $assignments = app(ManageAssignments::class);

    expect(fn () => $assignments->assignHouse($o->headB, $f->houseA, $o->b1->person))->toThrow(AuthorizationException::class)
        ->and(fn () => $assignments->assignHouse($o->a1, $f->houseA, $o->a2->person))->toThrow(AuthorizationException::class)
        ->and(fn () => $assignments->assignHouse($o->headA, $f->houseA, $o->a1->person))->toThrow(GeoRuleViolation::class)
        // A candidate cannot record visits, so cannot answer for a house.
        ->and(fn () => $assignments->assignHouse($o->admin, $f->houseA, userWithRoles('candidate')->person))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $assignments->assignTerritory($o->headA, $f->b1Area, $o->a2->person))->toThrow(AuthorizationException::class);

    // "2–3 дома": the limit of the settings; a whole territory is not counted.
    $houses = app(ManageHouses::class);
    foreach ([21, 22] as $number) {
        $assignments->assignHouse($o->headA, $houses->create($o->headA, ['territory_id' => $f->c1->id, 'street' => 'str. Ismail', 'number' => (string) $number]), $o->a1->person);
    }
    $fourth = $houses->create($o->headA, ['territory_id' => $f->c1->id, 'street' => 'str. Ismail', 'number' => '23']);
    expect(fn () => $assignments->assignHouse($o->headA, $fourth, $o->a1->person))->toThrow(GeoRuleViolation::class);

    app(FieldSettings::class)->update($o->admin, ['houses_per_agitator' => 0]);
    expect($assignments->assignHouse($o->headA, $fourth, $o->a1->person)->house_id)->toBe($fourth->id)
        ->and($o->a1->notifications()->where('data->kind', 'field_assigned')->count())->toBe(4)
        ->and(journalCount('geo.assignment.created'))->toBe(6);
});

it('records a visit: the status, the attempts, the date to come back', function () {
    $o = $this->org;
    $f = $this->field;

    $first = $f->visit($o->a1, $f->houseA, '3', 'not_home', ['next_visit_on' => now()->addDays(2)->toDateString()]);
    $flat = $f->flat($f->houseA, '3');
    expect($first->attempt_no)->toBe(1)->and($flat->status_code)->toBe('not_home')->and($flat->attempts)->toBe(1)
        ->and($flat->next_visit_on->toDateString())->toBe(now()->addDays(2)->toDateString())
        ->and($flat->last_visit_person_id)->toBe($o->a1->person_id);

    $second = $f->visit($o->a1, $f->houseA, '3', 'supporter');
    $flat->refresh();
    expect($second->attempt_no)->toBe(2)->and($flat->status_code)->toBe('supporter')->and($flat->attempts)->toBe(2)
        ->and($flat->next_visit_on)->toBeNull()
        ->and(Visit::query()->where('apartment_id', $flat->id)->count())->toBe(2)
        ->and(journalCount('geo.visit.recorded'))->toBe(2);

    expect(fn () => $f->visit($o->a1, $f->houseA, '4', 'not_visited'))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $f->visit($o->a1, $f->houseA, '4', 'no_such_status'))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $f->visit($o->a1, $f->houseA, '4', 'not_home', ['next_visit_on' => now()->subDay()->toDateString()]))->toThrow(GeoRuleViolation::class)
        ->and(fn () => $f->visit($o->a1, $f->houseA, '4', 'supporter', ['visited_at' => now()->addDay()]))->toThrow(GeoRuleViolation::class)
        // Not one's own house — whatever the role.
        ->and(fn () => $f->visit($o->a1, $f->houseA2, '1', 'supporter'))->toThrow(AuthorizationException::class)
        ->and(fn () => $f->visit($o->a2, $f->houseA, '4', 'supporter'))->toThrow(AuthorizationException::class)
        ->and(fn () => $f->visit($o->headA, $f->houseA, '4', 'supporter'))->toThrow(AuthorizationException::class);
});

it('sets the agitator a task to come back — a volunteer included', function () {
    $f = $this->field;
    $on = now()->addDays(3);

    $visit = $f->visit($f->volunteer, $f->houseA2, '2', 'not_home', ['next_visit_on' => $on->toDateString(), 'create_task' => true]);
    $task = Task::query()->findOrFail($visit->fresh()->task_id);

    expect($task->type_code)->toBe('follow_up_visit')
        ->and($task->title)->toContain('Ștefan cel Mare 5')->toContain('2')
        ->and($task->due_at->toDateString())->toBe($on->toDateString())
        ->and($task->people()->where('person_id', $f->volunteer->person_id)->exists())->toBeTrue()
        ->and($task->status_code)->toBe('todo');

    // The relation gives the volunteer this kind of task only — not tasks in general.
    expect(app(AuthorizationService::class)->can($f->volunteer, 'tasks.create', new Task(['type_code' => 'assignment', 'creator_person_id' => $f->volunteer->person_id])))->toBeFalse();
});

it('shows a personal note to its author only, and a note for the staff — to the staff', function () {
    $o = $this->org;
    $f = $this->field;
    $notes = app(FieldNotes::class);
    $bodies = fn ($viewer): array => $notes->visibleTo($viewer, $f->houseA)->orderBy('id')->get()->pluck('body')->all();

    $f->visit($o->a1, $f->houseA, '1', 'contacted', ['note' => 'Câine în curte, a suna la interfon 14', 'note_visibility' => 'personal']);
    $f->visit($o->a1, $f->houseA, '2', 'supporter', ['note' => 'Vrea să ajute la distribuirea pliantelor', 'note_visibility' => 'team']);
    // No choice — the setting decides: for the staff.
    $f->visit($o->a1, $f->houseA, '3', 'undecided', ['note' => 'A cerut programul']);

    expect($bodies($o->a1))->toHaveCount(3)
        ->and($bodies($o->headA))->toBe(['Vrea să ajute la distribuirea pliantelor', 'A cerut programul'])
        ->and($bodies($o->regionHead))->toHaveCount(2)
        ->and($bodies($o->orgHead))->toHaveCount(2)
        // The personal note is closed to everybody else — the super admin included.
        ->and($bodies($o->admin))->not->toContain('Câine în curte, a suna la interfon 14')
        ->and($bodies($o->headB))->toBe([])
        ->and($bodies($o->a2))->toBe([])
        ->and($notes->canRead($o->headA, ApartmentNote::query()->where('visibility', 'personal')->sole()))->toBeFalse();

    // Encrypted at rest; never in the journal.
    $raw = DB::table('apartment_notes')->pluck('body')->implode(' ');
    expect($raw)->not->toContain('Câine')->not->toContain('pliantelor')
        ->and(DB::table('journal_entries')->where('event_type', 'geo.visit.recorded')->get()->map(fn ($row) => json_encode($row))->implode(' '))
        ->not->toContain('Câine')->not->toContain('pliantelor');
});

it('gives the phone the houses of the agitator, and nothing else', function () {
    $o = $this->org;
    $f = $this->field;
    $f->visit($o->a1, $f->houseA, '1', 'contacted', ['note' => 'Nota mea', 'note_visibility' => 'personal']);

    $snapshot = app(FieldSnapshot::class)->for($o->a1);

    expect(array_column($snapshot['houses'], 'id'))->toBe([$f->houseA->id])
        ->and($snapshot['houses'][0]['apartments'])->toHaveCount(12)
        ->and($snapshot['houses'][0]['apartments'][0])->toMatchArray(['number' => '1', 'status' => 'contacted', 'attempts' => 1])
        ->and($snapshot['houses'][0]['apartments'][0]['notes'][0]['body'])->toBe('Nota mea')
        ->and(array_column($snapshot['statuses'], 'code'))->toContain('not_home', 'supporter', 'refused')
        ->and(array_column(app(FieldSnapshot::class)->for($f->volunteer)['houses'], 'id'))->toBe([$f->houseA2->id])
        // A head opens the houses in the panel; the phone carries only houses one answers for.
        ->and(app(FieldSnapshot::class)->for($o->headA)['houses'])->toBe([]);
});

it('applies an offline operation once, however many times it is sent', function () {
    $o = $this->org;
    $f = $this->field;
    $sync = app(OfflineSync::class);
    $flat = $f->flat($f->houseA, '5');
    $operation = ($this->operation)($flat->id, ['note' => 'Fără rețea la scară', 'note_visibility' => 'team']);

    $first = $sync->apply($o->a1, 'device-1', [$operation]);
    $again = $sync->apply($o->a1, 'device-1', [$operation]);
    $third = $sync->apply($o->a1, 'device-2', [$operation, $operation]);

    expect($first[0])->toMatchArray(['status' => 'applied', 'duplicate' => false])
        ->and($first[0]['result']['apartment'])->toMatchArray(['status' => 'supporter', 'attempts' => 1])
        ->and($again[0])->toMatchArray(['status' => 'applied', 'duplicate' => true])
        ->and($again[0]['result'])->toBe($first[0]['result'])
        ->and($third)->toHaveCount(2)
        ->and(Visit::query()->where('apartment_id', $flat->id)->count())->toBe(1)
        ->and(ApartmentNote::query()->where('apartment_id', $flat->id)->count())->toBe(1)
        ->and($flat->fresh()->attempts)->toBe(1)
        ->and(Visit::query()->where('apartment_id', $flat->id)->sole())->toMatchArray(['source' => 'offline', 'operation_id' => $operation['operation_id']])
        ->and(FieldOperation::query()->where('operation_id', $operation['operation_id'])->sole()->received_count)->toBe(4)
        ->and(journalCount('geo.visit.recorded'))->toBe(1);
});

it('refuses offline operations that are not allowed, and remembers the refusal', function () {
    $o = $this->org;
    $f = $this->field;
    $sync = app(OfflineSync::class);
    $foreign = ($this->operation)($f->flat($f->houseA2, '1')->id);
    $own = ($this->operation)($f->flat($f->houseA, '6')->id);

    $answers = $sync->apply($o->a1, 'device-1', [
        $foreign,
        ($this->operation)(999999),
        ($this->operation)($f->flat($f->houseA, '7')->id, ['status_code' => 'not_visited']),
        ($this->operation)($f->flat($f->houseA, '7')->id, [], ['operation' => 'delete']),
        ['entity' => 'apartment', 'operation' => 'visit'],
        ($this->operation)($f->flat($f->houseA, '7')->id, [], ['operation_id' => 'not-a-uuid']),
        $own,
    ]);

    expect(array_column($answers, 'status'))->toBe(['rejected', 'rejected', 'rejected', 'rejected', 'rejected', 'rejected', 'applied'])
        ->and($answers[0]['error'])->toBe($answers[1]['error'])                    // "not yours" and "not found" answer alike
        ->and(Visit::query()->count())->toBe(1)
        ->and(FieldOperation::query()->where('status', 'rejected')->count())->toBe(4)
        // Sent again, a refusal stays a refusal — even if the right appeared meanwhile.
        ->and($sync->apply($o->a1, 'device-1', [$foreign])[0])->toMatchArray(['status' => 'rejected', 'duplicate' => true])
        // Somebody else's operation id is neither applied nor disclosed.
        ->and($sync->apply($f->volunteer, 'device-9', [$own])[0])->toMatchArray(['status' => 'rejected', 'duplicate' => true])
        ->and($f->flat($f->houseA, '6')->attempts)->toBe(1);
});

it('does not let an older offline visit overwrite a newer one', function () {
    $o = $this->org;
    $f = $this->field;
    $flat = $f->flat($f->houseA, '8');

    // Yesterday at the door, without a network: nobody home. Today, online: a supporter. Then the phone syncs.
    $f->visit($o->a1, $f->houseA, '8', 'supporter');
    $late = ($this->operation)($flat->id, ['status_code' => 'not_home', 'next_visit_on' => now()->toDateString()], ['client_timestamp' => now()->subDay()->toIso8601String()]);
    $answer = app(OfflineSync::class)->apply($o->a1, 'device-1', [$late]);

    expect($answer[0]['status'])->toBe('applied')
        ->and($flat->fresh())->toMatchArray(['status_code' => 'supporter', 'attempts' => 2])
        ->and($flat->fresh()->next_visit_on)->toBeNull()
        ->and(Visit::query()->where('apartment_id', $flat->id)->orderBy('visited_at')->pluck('status_code')->all())->toBe(['not_home', 'supporter'])
        // A clock far in the past or in the future is a broken clock.
        ->and(app(OfflineSync::class)->apply($o->a1, 'device-1', [($this->operation)($flat->id, [], ['client_timestamp' => now()->subDays(40)->toIso8601String()])])[0]['status'])->toBe('rejected');
});

it('counts the canvass over the houses the reader may see', function () {
    $o = $this->org;
    $f = $this->field;
    $summary = app(CanvassSummary::class);
    foreach (['1' => 'supporter', '2' => 'supporter', '3' => 'opposed', '4' => 'not_home', '5' => 'refused', '6' => 'undecided'] as $number => $status) {
        $f->visit($o->a1, $f->houseA, (string) $number, $status);
    }
    $f->visit($o->a1, $f->houseA, '4', 'not_home');
    $f->visit($f->volunteer, $f->houseA2, '1', 'supporter');
    $f->visit($o->b1, $f->houseB, '1', 'contacted');

    expect($summary->forHouse($f->houseA))->toMatchArray([
        'apartments' => 12, 'visited' => 6, 'contacts' => 4, 'supporters' => 2, 'attempts' => 7, 'visited_pct' => 50, 'supporter_pct' => 17,
    ]);

    $rows = fn ($reader): array => collect($summary->byTerritory($reader))->mapWithKeys(fn (array $row): array => [$row['territory']->code => $row['figures']])->all();
    $region = $rows($o->regionHead);
    $branchA = $rows($o->headA);

    expect(array_keys($region))->toEqualCanonicalizing(['md', 'chisinau', 'chisinau/centru', 'chisinau/centru/1', 'chisinau/centru/2', 'chisinau/botanica', 'chisinau/botanica/1'])
        ->and($region['chisinau'])->toMatchArray(['houses' => 3, 'apartments' => 26, 'visited' => 8, 'supporters' => 3])
        ->and($region['chisinau/centru'])->toMatchArray(['houses' => 2, 'apartments' => 18, 'visited' => 7, 'supporters' => 3, 'visited_pct' => 39])
        // The head of branch A: the sector Centru only — Botanica is not in the totals either.
        ->and(array_keys($branchA))->not->toContain('chisinau/botanica', 'chisinau/botanica/1')
        ->and($branchA['chisinau'])->toMatchArray(['houses' => 2, 'apartments' => 18])
        ->and(array_keys($rows($o->headB)))->not->toContain('chisinau/centru')
        ->and($rows($o->a1))->toBe([])                                          // an agitator has no summaries
        ->and(collect($summary->byTerritory($o->orgHead, $o->centru))->pluck('depth')->all())->toBe([0, 1, 1]);
});
