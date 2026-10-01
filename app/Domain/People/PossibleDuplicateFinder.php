<?php

declare(strict_types=1);

namespace App\Domain\People;

use App\Domain\People\Models\Person;
use App\Support\Phone\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Д-10: finds cards that may belong to the same human. Returns hints with the reason of the match;
 * it never links anything. The same service backs CRM de-duplication and import checks (ТЗ §27, §68);
 * the card may be unsaved (a row of an import file).
 */
final class PossibleDuplicateFinder
{
    public const string BY_EMAIL = 'email';

    public const string BY_PHONE = 'phone';

    public const string BY_NAME = 'name';

    /**
     * @return array<int, list<string>> existing person id => reasons
     */
    public function find(Person $person): array
    {
        $matches = [];
        $base = Person::query()->whereNull('duplicate_of_person_id')->whereNull('archived_at')
            ->when($person->exists, fn ($q) => $q->whereKeyNot($person->id));

        if (filled($person->email)) {
            $email = Str::lower((string) $person->email);
            // The address may be on the card or be the login e-mail of the person's account.
            $byEmail = (clone $base)->where(fn ($q) => $q
                ->whereRaw('LOWER(email) = ?', [$email])
                ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(email) = ?', [$email])));
            foreach ($byEmail->pluck('id') as $id) {
                $matches[$id][] = self::BY_EMAIL;
            }
        }

        $phone = PhoneNumber::normalize($person->phone);
        if ($phone !== null) {
            foreach ((clone $base)->where('phone', $phone)->pluck('id') as $id) {
                $matches[$id][] = self::BY_PHONE;
            }
        }

        if (filled($person->last_name)) {
            $candidates = (clone $base)
                ->whereRaw('LOWER(last_name) = ?', [Str::lower((string) $person->last_name)])
                ->get(['id', 'first_name', 'last_name']);
            foreach ($candidates as $candidate) {
                if ($this->sameName($person->first_name, $candidate->first_name)) {
                    $matches[$candidate->id][] = self::BY_NAME;
                }
            }
        }

        return array_map(fn (array $reasons): array => array_values(array_unique($reasons)), $matches);
    }

    private function sameName(string $a, string $b): bool
    {
        $normalize = fn (string $v): string => Str::lower(Str::ascii(trim($v)));

        return $normalize($a) === $normalize($b);
    }
}
