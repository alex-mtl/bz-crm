<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\ManageRelations;
use App\Domain\CRM\Actions\RecordInteraction;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\PersonTimeline;
use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Exceptions\CustomFieldViolation;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\People\Actions\ManagePeople;
use App\Domain\People\Exceptions\PeopleRuleViolation;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonStatusHistory;
use App\Domain\Profiles\Actions\ManageProfile;
use App\Domain\Profiles\Exceptions\ProfileRuleViolation;
use App\Domain\Profiles\Models\PersonProfile;
use App\Domain\Profiles\ProfileAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\CrmFixture;

/*
 * ФО §6.9.1 — the people registry: cards outside the org structure, scopes, history, custom fields,
 * relations, the interaction feed.
 */

beforeEach(function () {
    $this->crm = CrmFixture::build();
    $this->org = $this->crm->org;
    $this->people = app(ManagePeople::class);
});

it('lets a branch head create a supporter in the own branch, not in the other one', function () {
    $o = $this->org;
    $data = ['first_name' => 'Vera', 'last_name' => 'Ciobanu', 'person_type' => 'supporter', 'phone' => '069 123 456'];

    $person = $this->people->create($o->headA, [...$data, 'responsible_unit_id' => $o->branchA->id, 'territory_id' => $o->centru->id]);

    expect($person->phone)->toBe('37369123456')
        ->and(PersonStatusHistory::query()->where('person_id', $person->id)->sole()->new_value)->toBe('supporter')
        ->and(fn () => $this->people->create($o->headA, [...$data, 'responsible_unit_id' => $o->branchB->id]))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->people->create($o->a1, [...$data, 'responsible_unit_id' => $o->branchA->id]))->toThrow(AuthorizationException::class);
});

it('places a person outside the structure by the card: territory first, then the responsible unit', function () {
    $o = $this->org;
    $authz = app(AuthorizationService::class);
    $inCentru = $this->crm->supporter($o->centru);
    $ofBranchB = $this->crm->supporter(null, $o->branchB);
    $inBalti = $this->crm->supporter($o->baltiTerritory, $o->balti);

    // The operator is scoped to the territory Chișinău: Centru and the branch B unit (Botanica) are inside, Bălți is not.
    $visible = $authz->scopeQuery($this->crm->operator, 'crm.interactions.read', Person::query())->pluck('id')->all();

    expect($visible)->toContain($inCentru->id, $ofBranchB->id)->not->toContain($inBalti->id)
        ->and($authz->can($this->crm->operator, 'crm.interactions.read', $inCentru))->toBeTrue()
        ->and($authz->can($this->crm->operator, 'crm.interactions.read', $inBalti))->toBeFalse()
        ->and($authz->can($o->headB, 'people.update', $ofBranchB))->toBeTrue()
        ->and($authz->can($o->headB, 'people.update', $inCentru))->toBeFalse();
});

it('journals a change without copying names or contacts, keeps the type history and refuses to give a card away', function () {
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA);

    $this->people->update($o->headA, $person, ['last_name' => 'Nou', 'phone' => '+373 68 000 111', 'person_type' => 'partner']);

    $entry = JournalEntry::query()->where('event_type', 'people.person.updated')->sole();
    expect($entry->new_values['fields'])->toEqualCanonicalizing(['last_name', 'phone', 'person_type'])
        ->and(json_encode($entry->new_values))->not->toContain('Nou')->not->toContain('37368000111')
        ->and(PersonStatusHistory::query()->where('person_id', $person->id)->where('kind', 'type')->latest('id')->first())
        ->old_value->toBe('supporter')->new_value->toBe('partner')
        ->and(fn () => $this->people->update($o->headA, $person->fresh(), ['responsible_unit_id' => $o->branchB->id, 'territory_id' => $o->botanica->id]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->people->update($o->headB, $person->fresh(), ['last_name' => 'X']))->toThrow(AuthorizationException::class);
});

it('validates a card: name, e-mail, phone, type', function (array $data) {
    expect(fn () => $this->people->create($this->org->admin, ['first_name' => 'A', 'person_type' => 'supporter', ...$data]))
        ->toThrow(PeopleRuleViolation::class);
})->with([
    'no name' => [['first_name' => ' ']],
    'bad e-mail' => [['email' => 'not-an-email']],
    'bad phone' => [['phone' => '12']],
    'unknown type' => [['person_type' => 'alien']],
    'unknown territory' => [['territory_id' => 999999]],
]);

it('archives and restores a card; a person with an active account is not archived', function () {
    $o = $this->org;
    $person = $this->crm->supporter($o->centru);

    $this->people->archive($o->orgHead, $person, 'S-a mutat');
    expect($person->fresh()->isArchived())->toBeTrue()
        ->and(fn () => $this->people->archive($o->headA, $this->crm->supporter($o->centru, $o->branchA)))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->people->archive($o->orgHead, $o->a1->person))->toThrow(PeopleRuleViolation::class);

    $this->people->restore($o->orgHead, $person->fresh());
    expect($person->fresh()->isArchived())->toBeFalse()
        ->and(PersonStatusHistory::query()->where('person_id', $person->id)->pluck('kind')->all())->toBe(['type', 'archived', 'restored']);
});

it('lets staff fill the open profile of a person without an account, with a preferred contact — not of an account holder', function () {
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA);

    app(ManageProfile::class)->updateFor($o->headA, $person, ['birth_date' => '1990-05-17', 'gender' => 'female'], [
        ['contact_type' => 'phone', 'value' => '+373 69 111 222', 'visibility' => 'all', 'is_preferred' => true],
        ['contact_type' => 'telegram', 'value' => '@vera', 'visibility' => 'all'],
    ]);

    $contacts = app(ProfileAccess::class)->visibleContacts($this->crm->operator, $person);
    $profile = PersonProfile::query()->findOrFail($person->id);
    expect($contacts)->toHaveCount(2)
        // No owner, no management around the person: the role's right on the field group alone decides.
        ->and(app(ProfileAccess::class)->canSeeField($this->crm->operator, $person, 'people.fields.personal.read', $profile->personal_visibility))->toBe(app(AuthorizationService::class)->can($this->crm->operator, 'people.fields.personal.read', $person))
        ->and(app(ProfileAccess::class)->canSeeField($o->headA, $person, 'people.fields.personal.read', $profile->personal_visibility))->toBeTrue()
        ->and($contacts->firstWhere('contact_type', 'phone')->is_preferred)->toBeTrue()
        ->and(fn () => app(ManageProfile::class)->updateFor($o->headA, $o->a1->person, ['bio' => 'x']))->toThrow(ProfileRuleViolation::class)
        ->and(fn () => app(ManageProfile::class)->updateFor($o->headB, $person, ['bio' => 'x']))->toThrow(AuthorizationException::class);
});

it('adds custom fields without code and validates their values by type', function () {
    $fields = app(CustomFields::class);
    $admin = $this->org->admin;
    $fields->saveDefinition($admin, CustomField::PERSON, ['names' => ['ru' => 'Членский билет'], 'code' => 'card_no', 'field_type' => 'number'], 'ru');
    $fields->saveDefinition($admin, CustomField::PERSON, ['names' => ['ro' => 'Interes'], 'code' => 'interest', 'field_type' => 'select',
        'options' => [['ro' => 'Ecologie'], ['ro' => 'Educație']], 'applies_to' => ['supporter']], 'ro');
    $person = $this->crm->supporter();

    $fields->store(CustomField::PERSON, $person->id, ['card_no' => '0042', 'interest' => 'Ecologie'], 'supporter');

    expect($fields->values(CustomField::PERSON, $person->id))->toBe(['card_no' => '42', 'interest' => 'ecologie'])
        ->and(CustomField::query()->where('code', 'card_no')->sole()->name_ro)->toBe('Членский билет')
        ->and(fn () => $fields->store(CustomField::PERSON, $person->id, ['card_no' => 'abc']))->toThrow(CustomFieldViolation::class)
        ->and(fn () => $fields->store(CustomField::PERSON, $person->id, ['interest' => 'Sport'], 'supporter'))->toThrow(CustomFieldViolation::class)
        ->and(fn () => $fields->store(CustomField::PERSON, $person->id, ['interest' => 'Ecologie'], 'partner'))->toThrow(CustomFieldViolation::class)
        ->and(fn () => $fields->saveDefinition($this->org->headA, CustomField::PERSON, ['names' => ['ro' => 'X'], 'field_type' => 'text'], 'ro'))
        ->toThrow(AuthorizationException::class);
});

it('stores a relation once and reads it from both sides through the inverse type', function () {
    $relations = app(ManageRelations::class);
    [$inviter, $invited] = [$this->crm->supporter(), $this->crm->supporter()];

    $relations->add($this->crm->hr, $inviter, $invited, 'invited', 'La eveniment');

    expect($relations->of($this->crm->hr, $inviter)->sole()['type'])->toBe('A invitat')
        ->and($relations->of($this->crm->hr, $invited)->sole()['type'])->toBe('Invitat(ă) de')
        ->and($relations->of($this->crm->hr, $invited)->sole()['other']->id)->toBe($inviter->id)
        ->and(fn () => $relations->add($this->crm->hr, $invited, $inviter, 'invited_by'))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $relations->add($this->crm->hr, $inviter, $inviter, 'friend'))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $relations->add($this->org->a1, $inviter, $invited, 'friend'))->toThrow(AuthorizationException::class)
        ->and(journalCount('crm.relation.added'))->toBe(1);
});

it('lets an employee record a contact only with a person they work with', function () {
    $o = $this->org;
    $record = app(RecordInteraction::class);
    $person = $this->crm->supporter($o->centru, $o->branchA);

    expect(fn () => $record($o->a1, $person, 'call', ['summary' => 'A răspuns']))->toThrow(AuthorizationException::class);

    app(ManageLeads::class)->create($o->headA, $this->crm->pipeline, $person, ['responsible_person_id' => $o->a1->person_id]);
    app(AuthorizationService::class)->forget();
    $interaction = $record($o->a1, $person, 'call', ['summary' => 'A răspuns', 'direction' => 'out', 'duration_minutes' => 5]);

    $entry = JournalEntry::query()->where('event_type', 'crm.interaction.recorded')->sole();
    expect($interaction->author_person_id)->toBe($o->a1->person_id)
        ->and(json_encode($entry->new_values))->not->toContain('răspuns')
        ->and(fn () => $record($o->headA, $person, 'smoke_signal'))->toThrow(CrmRuleViolation::class)
        ->and(fn () => $record($o->headA, $person, 'call', ['occurred_at' => now()->addDay()]))->toThrow(CrmRuleViolation::class);
});

it('builds the feed from every source the viewer may read, and nothing more', function () {
    $o = $this->org;
    $person = $this->crm->supporter($o->centru, $o->branchA);
    app(RecordInteraction::class)($o->headA, $person, 'meeting', ['summary' => 'La sediu']);
    app(ManageLeads::class)->create($o->headA, $this->crm->pipeline, $person);
    $timeline = app(PersonTimeline::class);

    $forHead = $timeline->for($o->headA, $person)->pluck('source')->unique()->values()->all();
    // An ordinary employee reads the card, but neither the interaction feed, nor other people's leads, nor the status history.
    $forEmployee = $timeline->for($o->b1, $person)->pluck('source')->all();

    expect($forHead)->toEqualCanonicalizing(['interaction', 'lead', 'status'])
        ->and($forEmployee)->toBe([])
        ->and($timeline->for($o->headA, $person, ['lead'])->pluck('source')->unique()->all())->toBe(['lead']);
});
