<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\Enums\Note360Type;
use App\Domain\Profiles\Exceptions\ProfileRuleViolation;
use App\Domain\Profiles\Models\HrAssessment;
use App\Domain\Profiles\Models\InternalProfile;
use App\Domain\Profiles\Models\Note360;
use App\Domain\Profiles\Models\PsychologyNote;
use App\Domain\Profiles\Models\SecurityNote;
use App\Domain\Profiles\ProfileAccess;
use App\Support\Settings\SystemSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes to the confidential layers (ФО §6.3.2–6.3.4). The journal records who changed which layer of whom,
 * never the content: otherwise the journal itself would leak the layer.
 */
final readonly class ManageConfidentialLayers
{
    public function __construct(
        private AuthorizationService $authorization,
        private ProfileAccess $access,
        private SystemSettings $settings,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{home_address?: string|null, personal_phone?: string|null, emergency_contacts?: list<array<string, string>>|null}  $data
     */
    public function saveInternal(User $actor, Person $person, array $data): InternalProfile
    {
        $this->authorization->authorize($actor, 'profile.internal.update', $person);

        return DB::transaction(function () use ($actor, $person, $data): InternalProfile {
            $layer = InternalProfile::query()->firstOrNew(['person_id' => $person->id]);
            $layer->fill([...$data, 'updated_by_user_id' => $actor->id])->save();
            $this->journal->record('profile.layer.updated', $person, [], ['layer' => 'internal']);

            return $layer;
        });
    }

    /**
     * @param  array{rating?: int|null, potential?: string|null, strengths?: string|null, development?: string|null, recommendations?: string|null}  $data
     */
    public function addHrAssessment(User $actor, Person $person, array $data): HrAssessment
    {
        $this->authorization->authorize($actor, 'profile.hr.write', $person);

        return DB::transaction(function () use ($actor, $person, $data): HrAssessment {
            $assessment = HrAssessment::query()->create([...$data, 'person_id' => $person->id, 'author_user_id' => $actor->id]);
            $this->journal->record('profile.layer.updated', $person, [], ['layer' => 'hr', 'entry_id' => $assessment->id]);

            return $assessment;
        });
    }

    public function addPsychologyNote(User $actor, Person $person, string $body): PsychologyNote
    {
        $this->authorization->authorize($actor, 'profile.psychology.write', $person);
        $this->ensureNotSelf($actor, $person);

        return DB::transaction(function () use ($actor, $person, $body): PsychologyNote {
            $note = PsychologyNote::query()->create(['person_id' => $person->id, 'author_user_id' => $actor->id, 'body' => trim($body)]);
            $this->journal->record('profile.layer.updated', $person, [], ['layer' => 'psychology', 'entry_id' => $note->id]);

            return $note;
        });
    }

    public function addSecurityNote(User $actor, Person $person, string $body): SecurityNote
    {
        $this->authorization->authorize($actor, 'profile.security.write', $person);

        return DB::transaction(function () use ($actor, $person, $body): SecurityNote {
            $note = SecurityNote::query()->create(['person_id' => $person->id, 'author_user_id' => $actor->id, 'body' => trim($body)]);
            $this->journal->record('profile.layer.updated', $person, [], ['layer' => 'security', 'entry_id' => $note->id]);

            return $note;
        });
    }

    public function addNote360(User $author, Person $subject, Note360Type $type, string $body): Note360
    {
        if (! $this->access->mayWriteNote360About($author, $subject)) {
            throw new AuthorizationException(__('access.denied'));
        }
        if (trim($body) === '') {
            throw ProfileRuleViolation::because('empty_note');
        }

        return DB::transaction(function () use ($author, $subject, $type, $body): Note360 {
            $note = Note360::query()->create([
                'subject_person_id' => $subject->id,
                'author_user_id' => $author->id,
                'author_person_id' => $author->person_id,
                'type' => $type,
                'body' => trim($body),
            ]);
            $this->journal->record('notes360.created', $subject, [], ['note_id' => $note->id, 'type' => $type->value]);

            return $note;
        });
    }

    /**
     * Only the author edits; the type stays as chosen at creation (Д-14).
     */
    public function editNote360(User $author, Note360 $note, string $body): Note360
    {
        if ($note->author_user_id !== $author->id || ! $author->isActive()) {
            throw new AuthorizationException(__('access.denied'));
        }
        if (trim($body) === '') {
            throw ProfileRuleViolation::because('empty_note');
        }

        return DB::transaction(function () use ($note, $body): Note360 {
            $note->update(['body' => trim($body)]);
            $this->journal->record('notes360.updated', Person::query()->find($note->subject_person_id), [], ['note_id' => $note->id]);

            return $note;
        });
    }

    public function deleteNote360(User $author, Note360 $note): void
    {
        if ($note->author_user_id !== $author->id || ! $author->isActive()) {
            throw new AuthorizationException(__('access.denied'));
        }

        DB::transaction(function () use ($note): void {
            $subject = Person::query()->find($note->subject_person_id);
            $note->delete();
            $this->journal->record('notes360.deleted', $subject, ['note_id' => $note->id, 'type' => $note->type->value]);
        });
    }

    /**
     * Д-14 setting: about whom one may write — own unit (default) or any employee.
     */
    public function setNotes360Scope(User $actor, string $scope): void
    {
        $this->authorization->authorize($actor, 'system.settings.manage');
        if (! in_array($scope, [ProfileAccess::NOTES360_SCOPE_OWN_UNIT, ProfileAccess::NOTES360_SCOPE_ANY], true)) {
            throw ProfileRuleViolation::because('invalid_setting');
        }

        DB::transaction(function () use ($actor, $scope): void {
            $old = $this->access->notes360Scope();
            $this->settings->put(ProfileAccess::NOTES360_SCOPE_KEY, $scope, $actor->id);
            $this->journal->record('profile.settings.updated', null, ['notes360_scope' => $old], ['notes360_scope' => $scope]);
        });
    }

    private function ensureNotSelf(User $actor, Person $person): void
    {
        if ($actor->person_id === $person->id) {
            throw ProfileRuleViolation::because('not_about_yourself');
        }
    }
}
