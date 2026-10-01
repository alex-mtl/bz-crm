<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;

/**
 * Person pickers: only people the viewer may see (people.read) — autocomplete must not leak either (ТЗ §52).
 */
final class PersonSearch
{
    /**
     * @param  (callable(Builder<Person>): mixed)|null  $constraint
     * @return array<int, string>
     */
    public static function search(string $term, ?callable $constraint = null, bool $activeUsersOnly = false): array
    {
        $viewer = Filament::auth()->user();
        if (! $viewer instanceof User) {
            return [];
        }
        $query = app(AuthorizationService::class)->scopeQuery($viewer, 'people.read', Person::query())
            ->where(fn (Builder $q) => $q->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%"));
        if ($activeUsersOnly) {
            $query->whereHas('user', fn (Builder $u) => $u->where('status', 'active'));
        }
        if ($constraint !== null) {
            $constraint($query);
        }

        return $query->orderBy('last_name')->limit(25)->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all();
    }

    public static function label(mixed $id): ?string
    {
        return $id !== null ? Person::query()->find($id)?->fullName() : null;
    }
}
