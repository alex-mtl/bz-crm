<?php

declare(strict_types=1);

namespace App\Domain\People\Actions;

use App\Domain\Audit\EventJournal;
use App\Domain\People\Events\PersonSaved;
use App\Domain\People\Models\Person;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A candidate is a Person without a User (ФО §6.1, path 3): an external participant from a public form.
 * The entity is laid down in phase 1; real intake from forms comes in phase 10.
 */
final readonly class RegisterCandidate
{
    public function __construct(private RecordLinkHints $recordHints, private EventJournal $journal) {}

    public function __invoke(string $firstName, ?string $lastName, ?string $email, ?string $phone, string $source, string $locale = 'ro'): Person
    {
        return DB::transaction(function () use ($firstName, $lastName, $email, $phone, $source, $locale): Person {
            $person = Person::query()->create([
                'first_name' => trim($firstName),
                'last_name' => $lastName !== null ? trim($lastName) : null,
                'email' => $email !== null ? Str::lower(trim($email)) : null,
                'phone' => PhoneNumber::normalize($phone),
                'person_type' => 'candidate',
                'preferred_locale' => $locale,
            ]);
            $this->journal->record('people.candidate.registered', $person, [], ['source' => $source]);
            ($this->recordHints)($person);
            PersonSaved::dispatch($person, true);

            return $person;
        });
    }
}
