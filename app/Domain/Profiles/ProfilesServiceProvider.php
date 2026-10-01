<?php

declare(strict_types=1);

namespace App\Domain\Profiles;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use App\Domain\People\PersonReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class ProfilesServiceProvider extends ServiceProvider
{
    /** Layers the owner never sees (ФО §6.3.2–6.3.3, §8). */
    private const array NOT_FOR_OWNER = [
        'profile.internal.read', 'profile.internal.update', 'profile.hr.read', 'profile.hr.write',
        'profile.psychology.write', 'profile.psychology.read_all', 'profile.security.read', 'profile.security.write',
    ];

    public function boot(EventTypeRegistry $types): void
    {
        $types->register(
            new EventType('profile.open.updated', EventCategory::Data),
            new EventType('profile.layer.viewed', EventCategory::Access, EventSeverity::Notice),
            new EventType('profile.layer.updated', EventCategory::Data, EventSeverity::Notice),
            new EventType('notes360.created', EventCategory::Data, EventSeverity::Notice),
            new EventType('notes360.updated', EventCategory::Data, EventSeverity::Notice),
            new EventType('notes360.deleted', EventCategory::Data, EventSeverity::Notice),
            new EventType('profile.settings.updated', EventCategory::Admin, EventSeverity::Notice),
        );

        // Merging cards (ТЗ §27): contacts and layer records follow; of the one-per-person rows the kept card
        // keeps its own, and takes the duplicate's only when it has none — the rest stays in the merge record.
        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->register('person_contacts', 'person_id');
            $references->register('hr_assessments', 'person_id');
            $references->register('psychology_notes', 'person_id');
            $references->register('security_notes', 'person_id');
            $references->register('notes_360', 'subject_person_id');
            foreach (['person_profiles', 'profile_internal'] as $table) {
                $references->registerHandler($table, function (int $keptId, int $mergedId) use ($table): array {
                    $row = DB::table($table)->where('person_id', $mergedId)->first();
                    if ($row === null) {
                        return [];
                    }
                    if (DB::table($table)->where('person_id', $keptId)->exists()) {
                        DB::table($table)->where('person_id', $mergedId)->delete();

                        return ['redundant' => [(array) $row]];
                    }
                    DB::table($table)->where('person_id', $mergedId)->update(['person_id' => $keptId]);

                    return ['moved' => [$mergedId]];
                });
            }
        });

        $this->callAfterResolving(AuthorizationService::class, function (AuthorizationService $authorization): void {
            $org = fn (): OrgStructure => $this->app->make(OrgStructure::class);
            $isDirectManager = fn (User $user, object $subject): bool => $subject instanceof Person
                && $org()->isDirectManager($user->person_id, $subject->id);

            // ФО §8: the direct manager sees the internal layer and the HR assessment of their report (Д-11).
            foreach (['profile.internal.read', 'profile.internal.update', 'profile.hr.read'] as $code) {
                $authorization->addRelation($code, AuthorizationService::RELATION_GRANT, $isDirectManager);
            }

            $authorization->addHardConstraint(fn (User $user, string $code, ?object $subject): bool => ! (
                in_array($code, self::NOT_FOR_OWNER, true) && $subject instanceof Person && $subject->id === $user->person_id
            ));
        });
    }
}
