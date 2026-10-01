<?php

declare(strict_types=1);

namespace App\Domain\Profiles;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\Enums\FieldVisibility;
use App\Domain\Profiles\Enums\Note360Type;
use App\Domain\Profiles\Models\Note360;
use App\Domain\Profiles\Models\PersonContact;
use App\Domain\Profiles\Models\PsychologyNote;
use App\Support\Settings\SystemSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who sees what in a profile (ФО §6.3, §8):
 *  - open-layer fields: the administrator's right on the field group ∧ the owner's circle (Д-13);
 *  - confidential layers: separate entities with their own rules, every view journaled (ФО §6.3.4);
 *  - "360" notes: the author's chosen type decides the circle; the subject never sees them (Д-14).
 */
final readonly class ProfileAccess
{
    public const string NOTES360_SCOPE_KEY = 'profiles.notes360.scope';

    public const string NOTES360_SCOPE_OWN_UNIT = 'own_unit';

    public const string NOTES360_SCOPE_ANY = 'any';

    public const array LAYERS = ['internal', 'hr', 'psychology', 'security', 'notes360'];

    public function __construct(
        private AuthorizationService $authorization,
        private OrgStructure $org,
        private TerritorialAccess $territories,
        private SystemSettings $settings,
        private EventJournal $journal,
    ) {}

    /**
     * Д-13: both the administrator's right on the field group and the owner's choice must allow it.
     */
    public function canSeeField(User $viewer, Person $owner, string $fieldGroupCode, FieldVisibility $visibility): bool
    {
        if ($viewer->person_id === $owner->id) {
            return $viewer->isActive();
        }

        return $this->authorization->can($viewer, $fieldGroupCode, $owner) && $this->inCircle($viewer->person_id, $owner->id, $visibility);
    }

    /**
     * The nested circles (Д-13): management ⊂ colleagues ⊂ region ⊂ all.
     */
    public function inCircle(int $viewerPersonId, int $ownerPersonId, FieldVisibility $visibility): bool
    {
        if ($visibility === FieldVisibility::All) {
            return true;
        }
        if (in_array($viewerPersonId, $this->org->management($ownerPersonId), true)) {
            return true;
        }
        if ($visibility === FieldVisibility::Management) {
            return false;
        }
        if ($this->org->areColleagues($viewerPersonId, $ownerPersonId)) {
            return true;
        }
        if ($visibility === FieldVisibility::Colleagues) {
            return false;
        }

        // "Region": exactly the territories of the owner's unit (Д-13, variant Б); without them — colleagues only.
        $unit = $this->org->unitOf($ownerPersonId);
        $ownerPaths = $unit !== null ? $unit->territories()->pluck('path')->map(fn ($p): string => (string) $p)->all() : [];

        return $ownerPaths !== [] && TerritorialAccess::overlap($ownerPaths, $this->territories->paths($viewerPersonId));
    }

    /**
     * @return Collection<int, PersonContact>
     */
    public function visibleContacts(User $viewer, Person $owner): Collection
    {
        $groups = CatalogItem::query()->ofCatalog('contact_types')->get()
            ->mapWithKeys(fn (CatalogItem $item): array => [$item->code => (string) $item->property('field_group', 'contacts')]);

        return PersonContact::query()->where('person_id', $owner->id)->orderBy('sort_order')->get()
            ->filter(fn (PersonContact $contact): bool => $this->canSeeField(
                $viewer, $owner, 'people.fields.'.($groups[$contact->contact_type] ?? 'contacts').'.read', $contact->visibility,
            ))->values();
    }

    public function canReadLayer(User $viewer, Person $person, string $layer): bool
    {
        return match ($layer) {
            'internal' => $this->authorization->can($viewer, 'profile.internal.read', $person),
            'hr' => $this->authorization->can($viewer, 'profile.hr.read', $person),
            'psychology' => $this->psychologyNotes($viewer, $person)->exists() || $this->authorization->can($viewer, 'profile.psychology.write', $person),
            'security' => $this->authorization->can($viewer, 'profile.security.read', $person),
            'notes360' => $this->notes360($viewer, $person)->isNotEmpty() || $this->mayWriteNote360About($viewer, $person),
            default => false,
        };
    }

    /**
     * Psychologist's notes: own notes only; everything only with the reserved right.
     *
     * @return Builder<PsychologyNote>
     */
    public function psychologyNotes(User $viewer, Person $person): Builder
    {
        $query = PsychologyNote::query()->where('person_id', $person->id)->latest();
        if ($viewer->person_id === $person->id) {
            return $query->whereRaw('1 = 0');
        }
        if ($this->authorization->can($viewer, 'profile.psychology.read_all', $person)) {
            return $query;
        }

        return $this->authorization->can($viewer, 'profile.psychology.write', $person)
            ? $query->where('author_user_id', $viewer->id)
            : $query->whereRaw('1 = 0');
    }

    /**
     * @return Collection<int, Note360>
     */
    public function notes360(User $viewer, Person $subject): Collection
    {
        return Note360::query()->where('subject_person_id', $subject->id)->latest()->get()
            ->filter(fn (Note360 $note): bool => $this->canReadNote360($viewer, $note))->values();
    }

    /**
     * Д-14. The subject is excluded before any other rule — even when they would be in the circle.
     */
    public function canReadNote360(User $viewer, Note360 $note): bool
    {
        if (! $viewer->isActive() || $viewer->person_id === $note->subject_person_id) {
            return false;
        }
        if ($viewer->id === $note->author_user_id) {
            return true;
        }
        if ($this->org->isOrganizationHead($viewer->person_id)) {
            return true;
        }

        return match ($note->type) {
            Note360Type::Feedback => $this->org->isDirectManager($viewer->person_id, $note->subject_person_id)
                || $this->authorization->can($viewer, 'notes360.feedback.read_hr'),
            Note360Type::Personal => in_array($viewer->person_id, $this->org->managerChain($note->author_person_id), true),
        };
    }

    /**
     * About whom one may write (Д-14 setting): own unit (default) or any employee of the organization.
     */
    public function mayWriteNote360About(User $author, Person $subject): bool
    {
        if ($author->person_id === $subject->id || ! $this->authorization->can($author, 'notes360.create')) {
            return false;
        }
        $subjectUnit = $this->org->unitOf($subject->id);
        if ($subjectUnit === null) {
            return false;
        }
        if ($this->notes360Scope() === self::NOTES360_SCOPE_ANY) {
            return true;
        }
        $authorUnit = $this->org->unitOf($author->person_id);

        return $authorUnit !== null && $authorUnit->covers($subjectUnit);
    }

    public function notes360Scope(): string
    {
        return $this->settings->get(self::NOTES360_SCOPE_KEY, self::NOTES360_SCOPE_OWN_UNIT) === self::NOTES360_SCOPE_ANY
            ? self::NOTES360_SCOPE_ANY
            : self::NOTES360_SCOPE_OWN_UNIT;
    }

    /**
     * ФО §6.3.4: every access to a confidential layer is journaled — who looked at whose layer and when.
     */
    public function recordView(Person $person, string $layer): void
    {
        $this->journal->record('profile.layer.viewed', $person, [], ['layer' => $layer]);
    }
}
