<?php

declare(strict_types=1);

namespace App\Filament\Resources\People\Concerns;

use App\Domain\Access\Admission\InviteUser;
use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\Actions\ManageAppeals;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\ManageRelations;
use App\Domain\CRM\Actions\RecordInteraction;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\PersonRelation;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\PersonTimeline;
use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\People\Actions\ManagePeople;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\Actions\ManageProfile;
use App\Domain\Profiles\Models\PersonContact;
use App\Domain\Profiles\Models\PersonProfile;
use App\Filament\Resources\Appeals\AppealResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Support\CustomFieldInputs;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\DB;

/**
 * The CRM side of a person card (ФО §6.9.1): the card fields, custom fields, relations, leads, appeals and the
 * feed of every touch. Each block is shown only to those who may read it; every action runs a domain action.
 */
trait HasCrmCard
{
    protected function crmActions(): ActionGroup
    {
        $c = fn (string $key): string => __('admin.crm.'.$key);

        return ActionGroup::make([
            Action::make('editCard')->label($c('edit_card'))->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $this->may('people.update'))
                ->fillForm(fn (): array => [
                    ...$this->person()->only(['first_name', 'last_name', 'person_type', 'preferred_locale', 'email', 'phone', 'territory_id', 'responsible_unit_id', 'source_code']),
                    'custom' => app(CustomFields::class)->values(CustomField::PERSON, $this->person()->id),
                ])
                ->schema(fn (): array => [...PersonResource::cardFields(), ...CustomFieldInputs::for(CustomField::PERSON, $this->person()->person_type)])
                ->action(fn (array $data) => $this->run(function () use ($data): void {
                    DB::transaction(function () use ($data): void {
                        $person = app(ManagePeople::class)->update($this->viewer(), $this->person(), $data);
                        if (isset($data['custom'])) {
                            app(CustomFields::class)->store(CustomField::PERSON, $person->id, (array) $data['custom'], $person->person_type);
                        }
                    });
                    $this->record = $this->person()->fresh() ?? $this->person();
                })),
            Action::make('editOpenProfile')->label($c('edit_profile'))->icon('heroicon-o-identification')
                // The open profile of an account holder is the owner's own (Д-13).
                ->visible(fn (): bool => $this->may('people.update') && $this->person()->user?->isActive() !== true)
                ->fillForm(function (): array {
                    $profile = PersonProfile::query()->find($this->person()->id);

                    return [
                        'birth_date' => $profile?->birth_date, 'gender' => $profile?->gender, 'languages' => $profile->languages ?? [],
                        'contacts' => PersonContact::query()->where('person_id', $this->person()->id)->orderBy('sort_order')->get()
                            ->map(fn (PersonContact $contact): array => ['contact_type' => $contact->contact_type, 'value' => $contact->value, 'is_preferred' => $contact->is_preferred])->all(),
                    ];
                })
                ->schema([
                    DatePicker::make('birth_date')->label(__('profiles.fields.birth_date'))->maxDate(now()),
                    Select::make('gender')->label(__('profiles.fields.gender'))->options(['female' => __('profiles.gender.female'), 'male' => __('profiles.gender.male')]),
                    TagsInput::make('languages')->label(__('profiles.fields.languages')),
                    Repeater::make('contacts')->label(__('profiles.fields.contacts'))->columns(3)->defaultItems(0)->schema([
                        Select::make('contact_type')->label(__('profiles.fields.contact_type'))->options(fn (): array => Options::catalog('contact_types'))->required(),
                        TextInput::make('value')->label(__('profiles.fields.contact_value'))->required()->maxLength(255),
                        Toggle::make('is_preferred')->label($c('preferred_channel'))->inline(false),
                    ]),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageProfile::class)->updateFor($this->viewer(), $this->person(), [
                    'birth_date' => $data['birth_date'] ?? null, 'gender' => $data['gender'] ?? null, 'languages' => array_values((array) ($data['languages'] ?? [])),
                ], array_map(fn (array $contact): array => [
                    'contact_type' => (string) $contact['contact_type'], 'value' => (string) $contact['value'],
                    'visibility' => 'all', 'is_preferred' => (bool) ($contact['is_preferred'] ?? false),
                ], array_values((array) ($data['contacts'] ?? [])))))),
            Action::make('grantAccess')->label($c('grant_access'))->icon('heroicon-o-key')
                // Д-22: an invitation for this very card — the account will belong to it, the history stays.
                ->visible(fn (): bool => app(AuthorizationService::class)->can($this->viewer(), 'users.invite')
                    && $this->person()->user === null && ! $this->person()->isArchived())
                ->modalDescription($c('grant_access_hint'))
                ->fillForm(fn (): array => ['email' => $this->person()->email, 'person_type' => 'employee'])
                ->schema([
                    TextInput::make('email')->label(__('identity.fields.email'))->email()->required()->maxLength(255),
                    Select::make('person_type')->label($c('becomes'))->options(fn (): array => Options::catalog('person_types'))->required(),
                    Select::make('roles')->label(__('admin.fields.roles'))->multiple()->options(fn (): array => Options::roles())->required(),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(InviteUser::class)(
                    $this->viewer(), (string) $data['email'], array_values((array) $data['roles']),
                    personType: (string) $data['person_type'], forPerson: $this->person(),
                ))),
            Action::make('addInteraction')->label($c('add_interaction'))->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn (): bool => $this->may('crm.interactions.create'))
                ->schema([
                    Select::make('kind_code')->label($c('interaction_kind'))->options(fn (): array => Options::catalog('interaction_kinds'))->required()->default('call'),
                    Select::make('direction')->label($c('direction'))->options(['out' => __('crm.directions.out'), 'in' => __('crm.directions.in')]),
                    DateTimePicker::make('occurred_at')->label($c('occurred_at'))->seconds(false)->default(now())->maxDate(now())->required(),
                    TextInput::make('duration_minutes')->label($c('duration'))->numeric()->integer()->minValue(0),
                    Textarea::make('summary')->label($c('summary'))->rows(3),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(RecordInteraction::class)($this->viewer(), $this->person(), (string) $data['kind_code'], [
                    'summary' => $data['summary'] ?? null, 'direction' => $data['direction'] ?? null, 'occurred_at' => $data['occurred_at'] ?? null,
                    'duration_minutes' => isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
                ]))),
            Action::make('addRelation')->label($c('add_relation'))->icon('heroicon-o-link')
                ->visible(fn (): bool => $this->may('crm.relations.manage'))
                ->schema([
                    Select::make('relation_code')->label($c('relation_type'))->options(fn (): array => Options::catalog('person_relation_types'))->required(),
                    Select::make('related_person_id')->label($c('related_person'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, fn ($query) => $query->whereKeyNot($this->person()->id)))
                        ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                    TextInput::make('note')->label($c('note'))->maxLength(255),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageRelations::class)->add(
                    $this->viewer(), $this->person(), Person::query()->findOrFail($data['related_person_id']), (string) $data['relation_code'], $data['note'] ?? null,
                ))),
            Action::make('removeRelation')->label($c('remove_relation'))->icon('heroicon-o-link-slash')->color('danger')
                ->visible(fn (): bool => $this->may('crm.relations.manage') && app(ManageRelations::class)->of($this->viewer(), $this->person())->isNotEmpty())
                ->schema([
                    Select::make('relation_id')->label($c('relation'))->required()
                        ->options(fn (): array => app(ManageRelations::class)->of($this->viewer(), $this->person())
                            ->mapWithKeys(fn (array $row): array => [$row['relation']->id => $row['type'].' — '.$row['other']->fullName()])->all()),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageRelations::class)->remove($this->viewer(), PersonRelation::query()->findOrFail($data['relation_id'])))),
            Action::make('createLead')->label($c('create_lead'))->icon('heroicon-o-funnel')
                ->visible(fn (): bool => app(AuthorizationService::class)->can($this->viewer(), 'leads.create') && ! $this->person()->isArchived())
                ->schema([
                    Select::make('pipeline_id')->label($c('pipeline'))->required()
                        ->options(fn (): array => Pipeline::query()->where('is_active', true)->orderBy('sort_order')->get()->mapWithKeys(fn (Pipeline $p): array => [$p->id => $p->name()])->all()),
                    Select::make('responsible_person_id')->label($c('responsible'))->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                        ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                    TextInput::make('title')->label($c('lead_title'))->maxLength(255),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageLeads::class)->create(
                    $this->viewer(), Pipeline::query()->findOrFail($data['pipeline_id']), $this->person(), [
                        'title' => $data['title'] ?? null,
                        'responsible_person_id' => isset($data['responsible_person_id']) ? (int) $data['responsible_person_id'] : null,
                    ]))),
            Action::make('registerAppeal')->label($c('register_appeal'))->icon('heroicon-o-inbox-arrow-down')
                ->visible(fn (): bool => app(AuthorizationService::class)->can($this->viewer(), 'appeals.create'))
                ->schema([
                    TextInput::make('title')->label($c('appeal_title'))->required()->maxLength(255),
                    Select::make('type_code')->label($c('appeal_type'))->options(fn (): array => Options::catalog('appeal_types'))->required(),
                    Select::make('source_code')->label(__('admin.people.source'))->options(fn (): array => Options::catalog('contact_sources')),
                    Textarea::make('body')->label($c('appeal_body'))->rows(4),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageAppeals::class)->register($this->viewer(), [
                    'title' => (string) $data['title'], 'type_code' => (string) $data['type_code'], 'source_code' => $data['source_code'] ?? null,
                    'body' => $data['body'] ?? null, 'person_id' => $this->person()->id,
                ]))),
            Action::make('archive')->label($c('archive'))->icon('heroicon-o-archive-box')->color('danger')
                ->visible(fn (): bool => $this->may('people.archive') && ! $this->person()->isArchived())
                ->schema([TextInput::make('note')->label($c('note'))->maxLength(500)])
                ->action(fn (array $data) => $this->run(function () use ($data): void {
                    app(ManagePeople::class)->archive($this->viewer(), $this->person(), $data['note'] ?? null);
                    $this->record = $this->person()->fresh() ?? $this->person();
                })),
            Action::make('restore')->label($c('restore'))->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => $this->may('people.archive') && $this->person()->isArchived() && $this->person()->duplicate_of_person_id === null)
                ->requiresConfirmation()
                ->action(fn () => $this->run(function (): void {
                    app(ManagePeople::class)->restore($this->viewer(), $this->person());
                    $this->record = $this->person()->fresh() ?? $this->person();
                })),
        ])->label('CRM')->icon('heroicon-o-funnel')->button();
    }

    /**
     * @return list<Section>
     */
    protected function crmSections(): array
    {
        $person = $this->person();
        $viewer = $this->viewer();
        $authorization = app(AuthorizationService::class);
        $c = fn (string $key): string => __('admin.crm.'.$key);
        $seesContacts = $authorization->can($viewer, 'people.fields.contacts.read', $person);
        $custom = CustomFieldInputs::display(CustomField::PERSON, $person->id, $person->person_type);
        $sourceLabels = Options::catalog('contact_sources');

        $leads = $authorization->scopeQuery($viewer, 'pipelines.read', Lead::query())->with(['pipeline', 'stage', 'responsible'])
            ->where('person_id', $person->id)->latest('id')->get();
        $appeals = $authorization->scopeQuery($viewer, 'appeals.read', Appeal::query())->with('responsible')
            ->where('person_id', $person->id)->latest('id')->get();
        $relations = app(ManageRelations::class)->of($viewer, $person);
        $timeline = app(PersonTimeline::class)->for($viewer, $person, [], 50);

        return [
            Section::make($c('card'))->columns(3)->schema([
                TextEntry::make('crm_status')->hiddenLabel()->badge()->color('danger')->columnSpanFull()
                    ->visible($person->isArchived())
                    ->state($person->duplicate_of_person_id !== null
                        ? $c('merged_into').' '.(Person::query()->find($person->duplicate_of_person_id)?->fullName() ?? '#'.$person->duplicate_of_person_id)
                        : __('admin.people.archived'))
                    ->url($person->duplicate_of_person_id !== null ? PersonResource::getUrl('view', ['record' => $person->duplicate_of_person_id]) : null),
                TextEntry::make('crm_territory')->label(__('admin.territories.singular'))->state(Places::territoryName($person->territory_id))->placeholder('—'),
                TextEntry::make('crm_unit')->label(__('admin.people.responsible_unit'))->state(Places::unitName($person->responsible_unit_id))->placeholder('—'),
                TextEntry::make('crm_source')->label(__('admin.people.source'))->placeholder('—')
                    ->state($person->source_code !== null ? ($sourceLabels[$person->source_code] ?? $person->source_code) : null),
                TextEntry::make('crm_email')->label(__('identity.fields.email'))->state($person->email)->placeholder('—')->copyable()->visible($seesContacts),
                TextEntry::make('crm_phone')->label(__('admin.people.phone'))->state($person->phone !== null ? '+'.$person->phone : null)->placeholder('—')->copyable()->visible($seesContacts),
                KeyValueEntry::make('crm_custom')->label(__('admin.custom_fields.plural'))->state($custom)->visible($custom !== [])->columnSpanFull()
                    ->keyLabel($c('field'))->valueLabel($c('value')),
            ]),
            Section::make($c('relations'))->visible($relations->isNotEmpty())->collapsible()->schema([
                RepeatableEntry::make('crm_relations')->hiddenLabel()->columns(3)
                    ->state($relations->map(fn (array $row): array => [
                        'type' => $row['type'], 'name' => $row['other']->fullName(), 'note' => $row['relation']->note,
                    ])->all())
                    ->schema([
                        TextEntry::make('type')->hiddenLabel()->badge(),
                        TextEntry::make('name')->hiddenLabel()->weight('bold'),
                        TextEntry::make('note')->hiddenLabel()->placeholder(''),
                    ]),
            ]),
            Section::make($c('leads'))->visible($leads->isNotEmpty())->collapsible()->schema([
                RepeatableEntry::make('crm_leads')->hiddenLabel()->columns(4)
                    ->state($leads->map(fn (Lead $lead): array => [
                        'pipeline' => $lead->pipeline->name(), 'stage' => $lead->stage->name(),
                        'status' => __('crm.lead_statuses.'.$lead->status), 'responsible' => $lead->responsible?->fullName(),
                    ])->all())
                    ->schema([
                        TextEntry::make('pipeline')->hiddenLabel()->weight('bold'),
                        TextEntry::make('stage')->hiddenLabel(),
                        TextEntry::make('status')->hiddenLabel()->badge(),
                        TextEntry::make('responsible')->hiddenLabel()->placeholder('—'),
                    ]),
                TextEntry::make('crm_leads_link')->hiddenLabel()->state($c('open_leads'))->url(LeadResource::getUrl('index', ['search' => $person->last_name ?? $person->first_name])),
            ]),
            Section::make($c('appeals'))->visible($appeals->isNotEmpty())->collapsible()->schema([
                RepeatableEntry::make('crm_appeals')->hiddenLabel()->columns(4)
                    ->state($appeals->map(fn (Appeal $appeal): array => [
                        'number' => $appeal->number, 'title' => $appeal->title,
                        'status' => __('crm.appeal_statuses.'.$appeal->status), 'responsible' => $appeal->responsible?->fullName(),
                    ])->all())
                    ->schema([
                        TextEntry::make('number')->hiddenLabel()->weight('bold'),
                        TextEntry::make('title')->hiddenLabel(),
                        TextEntry::make('status')->hiddenLabel()->badge(),
                        TextEntry::make('responsible')->hiddenLabel()->placeholder('—'),
                    ]),
                TextEntry::make('crm_appeals_link')->hiddenLabel()->state($c('open_appeals'))->url(AppealResource::getUrl('index', ['search' => $person->last_name ?? $person->first_name])),
            ]),
            Section::make($c('timeline'))->visible($timeline->isNotEmpty())->collapsible()->schema([
                RepeatableEntry::make('crm_timeline')->hiddenLabel()->columns(4)
                    ->state($timeline->map(fn (array $entry): array => [
                        'at' => $entry['at']->isoFormat('LLL'), 'source' => $c('sources.'.$entry['source']),
                        'title' => $entry['title'], 'details' => $entry['details'],
                    ])->all())
                    ->schema([
                        TextEntry::make('at')->hiddenLabel(),
                        TextEntry::make('source')->hiddenLabel()->badge()->color('gray'),
                        TextEntry::make('title')->hiddenLabel()->weight('bold'),
                        TextEntry::make('details')->hiddenLabel()->placeholder(''),
                    ]),
            ]),
        ];
    }
}
