<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Support\Translation\TranslatedNames;
use Illuminate\Support\Facades\DB;

final readonly class CreateRole
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array<string, string|null>  $names  locale => name; missing languages are auto-copied (Д-16)
     */
    public function __invoke(User $actor, string $code, array $names, string $sourceLocale): Role
    {
        $this->authorization->authorize($actor, 'roles.manage');
        $translated = TranslatedNames::complete($names, $sourceLocale);

        return DB::transaction(function () use ($code, $translated): Role {
            $role = Role::query()->create([
                'code' => $code,
                'name_ro' => $translated->names['ro'],
                'name_ru' => $translated->names['ru'],
                'name_en' => $translated->names['en'],
                'unverified_locales' => $translated->unverified,
                'is_system' => false,
            ]);
            $this->journal->record('access.role.created', $role, [], ['code' => $code, 'names' => $translated->names]);

            return $role;
        });
    }
}
