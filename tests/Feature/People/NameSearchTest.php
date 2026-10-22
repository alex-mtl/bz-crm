<?php

use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\People\NameSearch;
use App\Filament\Pages\AccessSimulator;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Resources\Tasks\TaskResource;
use Livewire\Livewire;

/*
 * Finding a person by name the way people type it: the whole name, in any order, in Cyrillic too.
 */

beforeEach(function () {
    foreach ([['Gheorghe', 'Arama'], ['Diana', 'Popescu'], ['Ion', 'Botnaru'], ['Radu', 'Ceban'], ['Olga', 'Sârbu'], ['Victor', 'Ciobanu'], ['Ana', 'Lungu']] as [$first, $last]) {
        Person::factory()->create(['first_name' => $first, 'last_name' => $last]);
    }
    $this->found = fn (string $term): array => Person::query()->matchingName($term)->orderBy('last_name')->get()
        ->map(fn (Person $person): string => $person->fullName())->all();
});

it('finds a person by the whole name, in any order, by part of a word', function () {
    expect(($this->found)('Gheorghe Arama'))->toBe(['Gheorghe Arama'])
        ->and(($this->found)('Arama Gheorghe'))->toBe(['Gheorghe Arama'])
        ->and(($this->found)('  arama  '))->toBe(['Gheorghe Arama'])
        ->and(($this->found)('Gh Ar'))->toBe(['Gheorghe Arama'])
        ->and(($this->found)('Gheorghe Popescu'))->toBe([])
        ->and(($this->found)('Sarbu'))->toBe(['Olga Sârbu'])
        ->and(($this->found)('100%'))->toBe([]);
});

it('finds a Latin name typed in Cyrillic', function () {
    expect(($this->found)('Диана'))->toBe(['Diana Popescu'])
        ->and(($this->found)('Ион Ботнару'))->toBe(['Ion Botnaru'])
        ->and(($this->found)('Ботнару ион'))->toBe(['Ion Botnaru'])
        ->and(($this->found)('Чебан'))->toBe(['Radu Ceban'])
        ->and(($this->found)('Чобану'))->toBe(['Victor Ciobanu'])
        ->and(($this->found)('Сырбу'))->toBe(['Olga Sârbu'])
        ->and(NameSearch::words('Кира')[0])->toContain('Кира', 'cira', 'kira');
});

it('finds people in the access simulator, the task form and the search bar by their whole name', function () {
    $admin = userWithRoles('super_admin');
    $this->actingAs($admin);

    expect(array_values(Livewire::test(AccessSimulator::class)->set('search', 'Ион Ботнару')->instance()->candidates))->toBe([])
        ->and(PersonResource::getGlobalSearchResults('Gheorghe Arama')->pluck('title')->all())->toBe(['Gheorghe Arama'])
        ->and(PersonResource::getGlobalSearchResults('Диана')->pluck('title')->all())->toBe(['Diana Popescu']);

    // The simulator lists accounts; give Ion one.
    $ion = Person::query()->where('first_name', 'Ion')->sole();
    $user = User::factory()->create(['person_id' => $ion->id]);
    expect(array_keys(Livewire::test(AccessSimulator::class)->set('search', 'Ион Ботнару')->instance()->candidates))->toBe([$user->id])
        // The task form shows the people one may assign before anything is typed.
        ->and(TaskResource::assignableSearch(''))->not->toBe([]);
});
