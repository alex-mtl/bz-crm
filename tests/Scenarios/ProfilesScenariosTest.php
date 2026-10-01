<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\Actions\ManageConfidentialLayers;
use App\Domain\Profiles\Enums\Note360Type;
use App\Domain\Profiles\Models\Note360;
use App\Domain\Profiles\ProfileAccess;
use App\Filament\Resources\People\Pages\ViewPerson;
use Database\Seeders\Demo\Personas;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * IMPLEMENTATION-PLAN 2f — field visibility (Д-12, Д-13), confidential layers (ФО §8), "360" notes (Д-14).
 */

function contactTypesSeenBy(string $viewer, string $owner): array
{
    app(AuthorizationService::class)->forget();
    app(OrgStructure::class)->forget();

    return app(ProfileAccess::class)->visibleContacts(Personas::user($viewer), Personas::user($owner)->person)->pluck('contact_type')->all();
}

it('shows a "colleagues" contact to the branch and its head only; after a transfer former colleagues lose it (Д-12)', function () {
    // Sergiu's phone is for colleagues.
    expect(contactTypesSeenBy('branch_a_employee_1', 'branch_a_employee_3'))->toContain('phone')
        ->and(contactTypesSeenBy('branch_a_head', 'branch_a_employee_3'))->toContain('phone')
        ->and(contactTypesSeenBy('branch_b_employee_1', 'branch_a_employee_3'))->not->toContain('phone')
        ->and(contactTypesSeenBy('central_employee', 'branch_a_employee_3'))->not->toContain('phone');

    app(ManageMembership::class)->transfer(Personas::user('hr'), Personas::user('branch_a_employee_3')->person, Personas::unit('balti'), 'Mutare');
    expect(contactTypesSeenBy('branch_a_employee_1', 'branch_a_employee_3'))->not->toContain('phone');
});

it('shows a "management" field to the manager chain and the organization head, not to colleagues', function () {
    // Maria's e-mail is for management.
    expect(contactTypesSeenBy('branch_a_head', 'branch_a_employee_2'))->toContain('email')
        ->and(contactTypesSeenBy('chisinau_head', 'branch_a_employee_2'))->toContain('email')
        ->and(contactTypesSeenBy('org_head', 'branch_a_employee_2'))->toContain('email')
        ->and(contactTypesSeenBy('branch_a_employee_1', 'branch_a_employee_2'))->not->toContain('email');
});

it('shows a "region" field of Olga (Botanica) to her branch, the region head and Sergiu with a Botanica grant — not to the rest of branch A', function () {
    expect(contactTypesSeenBy('branch_b_employee_2', 'branch_b_employee_1'))->toContain('phone')
        ->and(contactTypesSeenBy('chisinau_head', 'branch_b_employee_1'))->toContain('phone')
        ->and(contactTypesSeenBy('branch_a_employee_3', 'branch_b_employee_1'))->toContain('phone')
        ->and(contactTypesSeenBy('branch_a_employee_1', 'branch_a_employee_1'))->not->toBeEmpty()
        ->and(contactTypesSeenBy('branch_a_employee_2', 'branch_b_employee_1'))->not->toContain('phone');
});

it('treats "region" of a unit without territories as colleagues and management (Diana)', function () {
    expect(contactTypesSeenBy('super_admin', 'central_employee'))->toContain('phone')
        ->and(contactTypesSeenBy('org_head', 'central_employee'))->toContain('phone')
        ->and(contactTypesSeenBy('branch_a_employee_1', 'central_employee'))->not->toContain('phone');
});

it('hides from the volunteer a phone the owner opened to everyone — the administrator\'s field right wins (Д-13)', function () {
    expect(contactTypesSeenBy('volunteer', 'branch_a_employee_1'))->not->toContain('phone')
        ->and(contactTypesSeenBy('branch_b_employee_1', 'branch_a_employee_1'))->toContain('phone');

    // The volunteer shares a task with Ion, so opens his card ("Св") — and still does not see the phone.
    $this->actingAs(Personas::user('volunteer'))->get('/admin/people/'.Personas::user('branch_a_employee_1')->person_id)
        ->assertOk()->assertDontSee('+373 69 100 001')->assertSee('@ion_botnaru_demo', false);
    // A person with no shared task stays invisible — not even "forbidden", simply not found.
    $this->get('/admin/people/'.Personas::user('balti_employee_2')->person_id)->assertNotFound();
});

it('lets the owner see all own fields', function () {
    expect(contactTypesSeenBy('branch_a_employee_2', 'branch_a_employee_2'))->toBe(['phone', 'email']);
});

it('shows a feedback note to its author, the subject\'s manager and the org head — not to the author\'s other bosses', function () {
    $access = app(ProfileAccess::class);
    $note = Note360::query()->where('subject_person_id', Personas::user('branch_a_employee_3')->person_id)->where('type', 'feedback')->sole();

    expect($access->canReadNote360(Personas::user('branch_a_employee_1'), $note))->toBeTrue()
        ->and($access->canReadNote360(Personas::user('branch_a_head'), $note))->toBeTrue()
        ->and($access->canReadNote360(Personas::user('org_head'), $note))->toBeTrue()
        ->and($access->canReadNote360(Personas::user('branch_a_employee_2'), $note))->toBeFalse()
        ->and($access->canReadNote360(Personas::user('branch_a_employee_3'), $note))->toBeFalse();
});

it('shows a personal note to its author and the author\'s chain; the subject\'s own manager outside it does not see it', function () {
    $access = app(ProfileAccess::class);
    $note = Note360::query()->where('author_person_id', Personas::user('branch_a_employee_3')->person_id)->where('type', 'personal')->sole();

    expect($access->canReadNote360(Personas::user('branch_a_head'), $note))->toBeTrue()
        ->and($access->canReadNote360(Personas::user('branch_a_employee_2'), $note))->toBeFalse();   // Ion's manager, not Sergiu's chain
});

it('never shows a personal note about one\'s own manager to that manager (Д-14)', function () {
    $note = Note360::query()->where('subject_person_id', Personas::user('branch_a_head')->person_id)->sole();

    expect(app(ProfileAccess::class)->canReadNote360(Personas::user('branch_a_head'), $note))->toBeFalse()
        ->and(app(ProfileAccess::class)->canReadNote360(Personas::user('chisinau_head'), $note))->toBeTrue();
});

it('allows notes only about the own unit, until the setting says "any employee"', function () {
    $layers = app(ManageConfidentialLayers::class);
    $ion = Personas::user('branch_a_employee_1');

    expect(fn () => $layers->addNote360($ion, Personas::user('branch_b_employee_1')->person, Note360Type::Feedback, 'x'))->toThrow(AuthorizationException::class);
    $layers->setNotes360Scope(Personas::user('super_admin'), ProfileAccess::NOTES360_SCOPE_ANY);
    expect($layers->addNote360($ion, Personas::user('branch_b_employee_1')->person, Note360Type::Feedback, 'Colaborare bună')->id)->toBeInt();
});

it('keeps the note type fixed and journals edits without the content', function () {
    $note = Note360::query()->where('subject_person_id', Personas::user('branch_a_employee_1')->person_id)->where('type', 'feedback')->sole();
    $edit = JournalEntry::query()->where('event_type', 'notes360.updated')->sole();

    expect($note->type)->toBe(Note360Type::Feedback)
        ->and($note->body)->toContain('răspunde repede')
        ->and(json_encode($edit->new_values))->not->toContain('răspunde');
});

it('follows the ФО §8 matrix: owners never see their HR assessment; HR does not see security or psychologist notes', function () {
    $access = app(ProfileAccess::class);
    $ion = Personas::user('branch_a_employee_1')->person;
    $pavel = Personas::user('branch_b_head')->person;
    $sergiu = Personas::user('branch_a_employee_3')->person;

    expect($access->canReadLayer(Personas::user('branch_a_employee_1'), $ion, 'hr'))->toBeFalse()
        ->and($access->notes360(Personas::user('branch_a_employee_1'), $ion))->toBeEmpty()
        ->and($access->canReadLayer(Personas::user('hr'), $ion, 'hr'))->toBeTrue()
        ->and($access->canReadLayer(Personas::user('hr'), $pavel, 'security'))->toBeFalse()
        ->and($access->psychologyNotes(Personas::user('hr'), $sergiu)->count())->toBe(0)
        ->and($access->psychologyNotes(Personas::user('psychologist'), $sergiu)->count())->toBe(1)
        ->and($access->canReadLayer(Personas::user('org_head'), $pavel, 'security'))->toBeTrue()
        ->and($access->canReadLayer(Personas::user('org_head'), $ion, 'hr'))->toBeTrue();
});

it('journals each opening of a confidential layer in the card', function () {
    $before = JournalEntry::query()->where('event_type', 'profile.layer.viewed')->count();
    $this->actingAs(Personas::user('hr'));

    Livewire::test(ViewPerson::class, ['record' => Personas::user('branch_a_employee_1')->person_id])
        ->mountAction('layer_hr')
        ->assertMountedActionModalSee('Planificarea timpului');

    expect(JournalEntry::query()->where('event_type', 'profile.layer.viewed')->count())->toBe($before + 1);
});

it('does not open the layers of an employee to a colleague', function () {
    $this->actingAs(Personas::user('branch_a_employee_3'));

    Livewire::test(ViewPerson::class, ['record' => Personas::user('branch_a_employee_1')->person_id])
        ->assertActionHidden('layer_hr')
        ->assertActionHidden('layer_internal')
        ->assertActionHidden('layer_security');
});

it('keeps confidential text encrypted at rest', function () {
    $raw = DB::table('notes_360')->value('body');

    expect($raw)->not->toContain(' ')->and(Person::query()->count())->toBeGreaterThan(0);
});
