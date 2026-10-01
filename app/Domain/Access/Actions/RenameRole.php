<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Support\Translation\TranslatedNames;
use Illuminate\Support\Facades\DB;

final readonly class RenameRole
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array<string, string|null>  $names  only the languages being changed
     */
    public function __invoke(User $actor, Role $role, array $names): Role
    {
        $this->authorization->authorize($actor, 'roles.manage');

        return DB::transaction(function () use ($role, $names): Role {
            $old = $role->only(['name_ro', 'name_ru', 'name_en']);
            foreach (TranslatedNames::locales() as $locale) {
                $value = trim((string) ($names[$locale] ?? ''));
                if ($value !== '') {
                    $role->setAttribute('name_'.$locale, $value);
                }
            }
            $role->unverified_locales = TranslatedNames::unverifiedAfterEdit($role->unverified_locales ?? [], $names);
            $role->save();
            $this->journal->record('access.role.updated', $role, $old, $role->only(['name_ro', 'name_ru', 'name_en']));

            return $role;
        });
    }
}
