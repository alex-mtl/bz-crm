<?php

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\Actions\CreateRole;
use App\Domain\Access\Actions\ManageTerritoryGrants;
use App\Domain\Access\Actions\SetRolePermissions;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Models\UserRole;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Profiles\Actions\ManageConfidentialLayers;
use App\Domain\Profiles\Actions\ManageProfile;
use App\Domain\Profiles\Enums\FieldVisibility;
use App\Domain\Profiles\Enums\Note360Type;
use App\Domain\Profiles\ProfileAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\OrgFixture;

beforeEach(function () {
    $this->org = OrgFixture::build();
    app(ImportReferenceCatalogs::class)();
});

function sees($viewer, $owner, FieldVisibility $visibility, string $group = 'people.fields.contacts.read'): bool
{
    app(AuthorizationService::class)->forget();

    return app(ProfileAccess::class)->canSeeField($viewer, $owner->person, $group, $visibility);
}

it('shows a "colleagues" field to the same unit and management only (Д-12, Д-13)', function () {
    $o = $this->org;

    expect(sees($o->a2, $o->a1, FieldVisibility::Colleagues))->toBeTrue()
        ->and(sees($o->headA, $o->a1, FieldVisibility::Colleagues))->toBeTrue()
        ->and(sees($o->regionHead, $o->a1, FieldVisibility::Colleagues))->toBeTrue()
        ->and(sees($o->orgHead, $o->a1, FieldVisibility::Colleagues))->toBeTrue()
        ->and(sees($o->b1, $o->a1, FieldVisibility::Colleagues))->toBeFalse()
        ->and(sees($o->central1, $o->a1, FieldVisibility::Colleagues))->toBeFalse();

    app(ManageMembership::class)->transfer($o->admin, $o->a1->person, $o->branchB, 'Reorganizare');
    expect(sees($o->a2, $o->a1, FieldVisibility::Colleagues))->toBeFalse()
        ->and(sees($o->b1, $o->a1, FieldVisibility::Colleagues))->toBeTrue();
});

it('shows a "management" field to the manager chain and the org head, not to colleagues', function () {
    $o = $this->org;

    expect(sees($o->headA, $o->a1, FieldVisibility::Management))->toBeTrue()
        ->and(sees($o->regionHead, $o->a1, FieldVisibility::Management))->toBeTrue()
        ->and(sees($o->orgHead, $o->a1, FieldVisibility::Management))->toBeTrue()
        ->and(sees($o->a2, $o->a1, FieldVisibility::Management))->toBeFalse();
});

it('shows a "region" field to everyone whose territories overlap the owner\'s unit territories', function () {
    $o = $this->org;

    expect(sees($o->regionHead, $o->b1, FieldVisibility::Region))->toBeTrue()
        ->and(sees($o->a1, $o->b1, FieldVisibility::Region))->toBeFalse();

    app(ManageTerritoryGrants::class)->grant($o->regionHead, $o->a1->person, $o->botanica, 'Campanie');
    expect(sees($o->a1, $o->b1, FieldVisibility::Region))->toBeTrue()
        ->and(sees($o->a2, $o->b1, FieldVisibility::Region))->toBeFalse();
});

it('treats "region" as "colleagues" for a unit without territories', function () {
    $o = $this->org;
    $colleague = $o->member($o->central);

    expect(sees($colleague, $o->central1, FieldVisibility::Region))->toBeTrue()
        ->and(sees($o->orgHead, $o->central1, FieldVisibility::Region))->toBeTrue()
        ->and(sees($o->a1, $o->central1, FieldVisibility::Region))->toBeFalse();
});

it('lets the administrator\'s field right win over the owner\'s choice: a volunteer never sees contacts', function () {
    $o = $this->org;
    $volunteer = $o->member($o->branchA);
    app(UserRole::class)->newQuery()->where('user_id', $volunteer->id)->delete();
    app(AssignRole::class)($o->admin, $volunteer, Role::query()->where('code', 'volunteer')->sole());

    expect(sees($volunteer, $o->a1, FieldVisibility::All))->toBeFalse()
        ->and(sees($volunteer, $o->a1, FieldVisibility::All, 'people.fields.messengers.read'))->toBeTrue()
        ->and(sees($o->a1, $o->a1, FieldVisibility::Management))->toBeTrue();
});

it('filters contacts by their field group and visibility', function () {
    $o = $this->org;
    app(ManageProfile::class)->update($o->a1, $o->a1->person, [], [
        ['contact_type' => 'phone', 'value' => '+37369000001', 'visibility' => 'colleagues'],
        ['contact_type' => 'telegram', 'value' => '@a1', 'visibility' => 'all'],
    ]);

    expect(app(ProfileAccess::class)->visibleContacts($o->a2, $o->a1->person)->pluck('contact_type')->all())->toBe(['phone', 'telegram'])
        ->and(app(ProfileAccess::class)->visibleContacts($o->b1, $o->a1->person)->pluck('contact_type')->all())->toBe(['telegram'])
        ->and(journalCount('profile.open.updated'))->toBe(1);
});

it('follows the ФО §8 matrix for confidential layers', function () {
    $o = $this->org;
    $hr = $o->member($o->central, ['hr' => ScopeType::Organization]);
    $security = $o->member($o->central, ['security' => ScopeType::Organization]);
    $access = app(ProfileAccess::class);

    expect($access->canReadLayer($o->headA, $o->a1->person, 'internal'))->toBeTrue()
        ->and($access->canReadLayer($o->headA, $o->a1->person, 'hr'))->toBeTrue()
        ->and($access->canReadLayer($o->regionHead, $o->a1->person, 'hr'))->toBeFalse()
        ->and($access->canReadLayer($o->a1, $o->a1->person, 'hr'))->toBeFalse()
        ->and($access->canReadLayer($o->a1, $o->a1->person, 'internal'))->toBeFalse()
        ->and($access->canReadLayer($hr, $o->a1->person, 'hr'))->toBeTrue()
        ->and($access->canReadLayer($hr, $o->a1->person, 'security'))->toBeFalse()
        ->and($access->canReadLayer($security, $o->a1->person, 'security'))->toBeTrue()
        ->and($access->canReadLayer($security, $o->a1->person, 'hr'))->toBeFalse();

    // A new manager takes over the "by relation" access at once (Д-11).
    app(ManageMembership::class)->changeManager($o->headA, $o->a2->person, $o->a1->person);
    app(AuthorizationService::class)->forget();
    expect($access->canReadLayer($o->a1, $o->a2->person, 'hr'))->toBeTrue()
        ->and($access->canReadLayer($o->headA, $o->a2->person, 'hr'))->toBeFalse();
});

it('keeps psychologist notes to their author', function () {
    $o = $this->org;
    $psy = $o->member($o->central, ['psychologist' => ScopeType::Organization]);
    $other = $o->member($o->central, ['psychologist' => ScopeType::Organization]);
    app(ManageConfidentialLayers::class)->addPsychologyNote($psy, $o->a1->person, 'Observație');

    expect(app(ProfileAccess::class)->psychologyNotes($psy, $o->a1->person)->count())->toBe(1)
        ->and(app(ProfileAccess::class)->psychologyNotes($other, $o->a1->person)->count())->toBe(0)
        ->and(app(ProfileAccess::class)->psychologyNotes($o->orgHead, $o->a1->person)->count())->toBe(0);
});

it('applies the "360" rules of Д-14', function () {
    $o = $this->org;
    $layers = app(ManageConfidentialLayers::class);
    $access = app(ProfileAccess::class);

    $feedback = $layers->addNote360($o->a2, $o->a1->person, Note360Type::Feedback, 'Lucrează bine în echipă.');
    expect($access->canReadNote360($o->a2, $feedback))->toBeTrue()
        ->and($access->canReadNote360($o->headA, $feedback))->toBeTrue()
        ->and($access->canReadNote360($o->orgHead, $feedback))->toBeTrue()
        ->and($access->canReadNote360($o->a1, $feedback))->toBeFalse()
        ->and($access->canReadNote360($o->regionHead, $feedback))->toBeFalse();

    // A personal note about one's own manager: the manager is excluded although in the author's chain.
    $personal = $layers->addNote360($o->a2, $o->headA->person, Note360Type::Personal, 'Notă de lucru.');
    expect($access->canReadNote360($o->headA, $personal))->toBeFalse()
        ->and($access->canReadNote360($o->regionHead, $personal))->toBeTrue();

    expect(fn () => $layers->addNote360($o->a2, $o->b1->person, Note360Type::Feedback, 'x'))->toThrow(AuthorizationException::class);
    $layers->setNotes360Scope($o->admin, ProfileAccess::NOTES360_SCOPE_ANY);
    expect($layers->addNote360($o->a2, $o->b1->person, Note360Type::Feedback, 'x')->id)->toBeInt();

    expect(fn () => $layers->editNote360($o->headA, $feedback, 'changed'))->toThrow(AuthorizationException::class);
    $layers->editNote360($o->a2, $feedback, 'Actualizat');
    expect(journalCount('notes360.updated'))->toBe(1);
});

it('hides confidential-view events from journal readers without the separate right', function () {
    $o = $this->org;
    app(ProfileAccess::class)->recordView($o->a1->person, 'hr');
    $auditor = $o->member($o->central);
    $role = app(CreateRole::class)($o->admin, 'auditor', ['ro' => 'Auditor'], 'ro');
    app(SetRolePermissions::class)($o->admin, $role, ['audit.read']);
    app(AssignRole::class)($o->admin, $auditor, $role);
    app(AuthorizationService::class)->forget();

    $types = fn ($user) => app(AuthorizationService::class)
        ->scopeQuery($user, 'audit.read', JournalEntry::query())->pluck('event_type')->all();

    expect($types($auditor))->not->toContain('profile.layer.viewed')
        ->and($types($o->orgHead))->toContain('profile.layer.viewed');
});
