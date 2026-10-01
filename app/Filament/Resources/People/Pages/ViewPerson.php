<?php

declare(strict_types=1);

namespace App\Filament\Resources\People\Pages;

use App\Domain\Access\Actions\ManageTerritoryGrants;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\TerritoryGrant;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgMembershipHistory;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\AccountLinkHint;
use App\Domain\People\Models\Person;
use App\Domain\Profiles\Actions\ManageConfidentialLayers;
use App\Domain\Profiles\Enums\Note360Type;
use App\Domain\Profiles\Models\HrAssessment;
use App\Domain\Profiles\Models\InternalProfile;
use App\Domain\Profiles\Models\Note360;
use App\Domain\Profiles\Models\PersonProfile;
use App\Domain\Profiles\Models\PsychologyNote;
use App\Domain\Profiles\Models\SecurityNote;
use App\Domain\Profiles\ProfileAccess;
use App\Filament\Resources\People\Concerns\HasCrmCard;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Support\Options;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

class ViewPerson extends ViewRecord
{
    use HasCrmCard;

    protected static string $resource = PersonResource::class;

    private function person(): Person
    {
        $record = $this->getRecord();
        assert($record instanceof Person);

        return $record;
    }

    private function viewer(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function access(): ProfileAccess
    {
        return app(ProfileAccess::class);
    }

    private function may(string $code): bool
    {
        return app(AuthorizationService::class)->can($this->viewer(), $code, $this->person());
    }

    private function run(callable $action): void
    {
        try {
            $action();
            Notification::make()->title(__('admin.saved'))->success()->send();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    private function author(int $userId): string
    {
        return User::query()->with('person')->find($userId)?->person->fullName() ?? '—';
    }

    /**
     * One action per confidential layer: shown only to those who may read it; opening it is journaled (ФО §6.3.4).
     *
     * @param  callable(): list<array{meta?: string, lines: array<string, string|null>}>  $entries
     */
    private function layerAction(string $layer, callable $entries): Action
    {
        return Action::make('layer_'.$layer)
            ->label(__('profiles.layers.'.$layer))
            ->icon('heroicon-o-lock-closed')
            ->color('gray')
            ->visible(fn (): bool => $this->access()->canReadLayer($this->viewer(), $this->person(), $layer))
            ->modalHeading(__('profiles.layers.'.$layer).' — '.$this->person()->fullName())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('admin.close'))
            ->mountUsing(fn () => $this->access()->recordView($this->person(), $layer))
            ->modalContent(fn (): View => view('filament.people.entries', ['entries' => $entries()]));
    }

    protected function getHeaderActions(): array
    {
        $person = $this->person();
        $f = fn (string $key): string => __('profiles.fields.'.$key);

        return [
            $this->crmActions(),
            ActionGroup::make([
                $this->layerAction('internal', function () use ($person, $f): array {
                    $layer = InternalProfile::query()->find($person->id);

                    return $layer === null ? [] : [[
                        'lines' => [
                            $f('home_address') => $layer->home_address,
                            $f('personal_phone') => $layer->personal_phone,
                            $f('emergency_contacts') => collect($layer->emergency_contacts ?? [])
                                ->map(fn (array $c): string => trim(($c['name'] ?? '').' ('.($c['relation'] ?? '').') '.($c['phone'] ?? '')))->implode("\n"),
                        ],
                    ]];
                }),
                $this->layerAction('hr', fn (): array => HrAssessment::query()->where('person_id', $person->id)->latest()->get()
                    ->map(fn (HrAssessment $a): array => [
                        'meta' => $a->created_at->isoFormat('LL').' · '.$this->author($a->author_user_id),
                        'lines' => [
                            $f('rating') => $a->rating !== null ? (string) $a->rating : null,
                            $f('potential') => $a->potential !== null ? __('profiles.potential.'.$a->potential) : null,
                            $f('strengths') => $a->strengths, $f('development') => $a->development, $f('recommendations') => $a->recommendations,
                        ],
                    ])->all()),
                $this->layerAction('psychology', fn (): array => $this->access()->psychologyNotes($this->viewer(), $person)->get()
                    ->map(fn (PsychologyNote $n): array => ['meta' => $n->created_at->isoFormat('LL'), 'lines' => [$f('body') => $n->body]])->all()),
                $this->layerAction('security', fn (): array => SecurityNote::query()->where('person_id', $person->id)->latest()->get()
                    ->map(fn (SecurityNote $n): array => [
                        'meta' => $n->created_at->isoFormat('LL').' · '.$this->author($n->author_user_id),
                        'lines' => [$f('body') => $n->body],
                    ])->all()),
                $this->layerAction('notes360', fn (): array => $this->access()->notes360($this->viewer(), $person)
                    ->map(fn (Note360 $n): array => [
                        'meta' => $n->created_at->isoFormat('LL').' · '.$n->type->label().' · '.$this->author($n->author_user_id),
                        'lines' => [$f('body') => $n->body],
                    ])->all()),
            ])->label(__('admin.people.layers'))->icon('heroicon-o-lock-closed')->button()->color('gray'),

            ActionGroup::make([
                Action::make('editInternal')->label(__('admin.people.edit_internal'))
                    ->visible(fn (): bool => $this->may('profile.internal.update'))
                    ->mountUsing(function (Schema $form): void {
                        $this->access()->recordView($this->person(), 'internal');
                        $layer = InternalProfile::query()->find($this->person()->id);
                        $form->fill($layer?->only(['home_address', 'personal_phone', 'emergency_contacts']) ?? []);
                    })
                    ->schema([
                        Textarea::make('home_address')->label($f('home_address')),
                        TextInput::make('personal_phone')->label($f('personal_phone'))->maxLength(60),
                        Repeater::make('emergency_contacts')->label($f('emergency_contacts'))->columns(3)->defaultItems(0)->schema([
                            TextInput::make('name')->label($f('emergency_name'))->required(),
                            TextInput::make('relation')->label($f('emergency_relation')),
                            TextInput::make('phone')->label($f('personal_phone'))->required(),
                        ]),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageConfidentialLayers::class)->saveInternal($this->viewer(), $this->person(), [
                        'home_address' => $data['home_address'] ?? null,
                        'personal_phone' => $data['personal_phone'] ?? null,
                        'emergency_contacts' => array_values((array) ($data['emergency_contacts'] ?? [])),
                    ]))),
                Action::make('addHr')->label(__('admin.people.add_hr'))
                    ->visible(fn (): bool => $this->may('profile.hr.write'))
                    ->schema([
                        Select::make('rating')->label($f('rating'))->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5']),
                        Select::make('potential')->label($f('potential'))->options(fn (): array => collect(['high', 'medium', 'low'])
                            ->mapWithKeys(fn (string $p): array => [$p => __('profiles.potential.'.$p)])->all()),
                        Textarea::make('strengths')->label($f('strengths')),
                        Textarea::make('development')->label($f('development')),
                        Textarea::make('recommendations')->label($f('recommendations')),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageConfidentialLayers::class)->addHrAssessment($this->viewer(), $this->person(), [
                        'rating' => isset($data['rating']) ? (int) $data['rating'] : null,
                        'potential' => $data['potential'] ?? null,
                        'strengths' => $data['strengths'] ?? null,
                        'development' => $data['development'] ?? null,
                        'recommendations' => $data['recommendations'] ?? null,
                    ]))),
                Action::make('addPsychology')->label(__('admin.people.add_psychology'))
                    ->visible(fn (): bool => $this->may('profile.psychology.write'))
                    ->schema([Textarea::make('body')->label($f('body'))->required()])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageConfidentialLayers::class)->addPsychologyNote($this->viewer(), $this->person(), (string) $data['body']))),
                Action::make('addSecurity')->label(__('admin.people.add_security'))
                    ->visible(fn (): bool => $this->may('profile.security.write'))
                    ->schema([Textarea::make('body')->label($f('body'))->required()])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageConfidentialLayers::class)->addSecurityNote($this->viewer(), $this->person(), (string) $data['body']))),
                Action::make('addNote360')->label(__('admin.people.add_note360'))
                    ->visible(fn (): bool => $this->access()->mayWriteNote360About($this->viewer(), $this->person()))
                    ->schema([
                        Select::make('type')->label($f('type'))->required()->helperText(__('admin.people.note360_type_hint'))
                            ->options(collect(Note360Type::cases())->mapWithKeys(fn (Note360Type $t): array => [$t->value => $t->label()])->all()),
                        Textarea::make('body')->label($f('body'))->required(),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageConfidentialLayers::class)->addNote360(
                        $this->viewer(), $this->person(), Note360Type::from((string) $data['type']), (string) $data['body'],
                    ))),
            ])->label(__('admin.people.add'))->icon('heroicon-o-plus')->button(),

            ActionGroup::make([
                Action::make('grantTerritory')->label(__('admin.people.grant_territory'))
                    ->visible(fn (): bool => app(ManageTerritoryGrants::class)->mayManageFor($this->viewer(), $this->person()))
                    ->schema([
                        Select::make('territory_id')->label(__('admin.territories.singular'))->required()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Territory::query()->search($search)->limit(30)->get()
                                ->mapWithKeys(fn (Territory $t): array => [$t->id => $t->name()])->all()),
                        TextInput::make('reason')->label(__('admin.org_units.reason'))->required()->maxLength(500),
                        DatePicker::make('expires_at')->label(__('admin.users.expires_at'))->minDate(now()->addDay()),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageTerritoryGrants::class)->grant(
                        $this->viewer(), $this->person(), Territory::query()->findOrFail($data['territory_id']), (string) $data['reason'],
                        isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
                    ))),
                Action::make('revokeTerritory')->label(__('admin.people.revoke_territory'))->color('danger')
                    ->visible(fn (): bool => app(ManageTerritoryGrants::class)->mayManageFor($this->viewer(), $this->person())
                        && TerritoryGrant::query()->where('person_id', $this->person()->id)->inEffect()->exists())
                    ->schema([
                        Select::make('grant_id')->label(__('admin.territories.singular'))->required()
                            ->options(fn (): array => TerritoryGrant::query()->with('territory')->where('person_id', $this->person()->id)->inEffect()->get()
                                ->mapWithKeys(fn (TerritoryGrant $g): array => [$g->id => $g->territory->name()])->all()),
                        TextInput::make('comment')->label(__('admin.org_units.reason'))->required(),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageTerritoryGrants::class)->revoke(
                        $this->viewer(), TerritoryGrant::query()->findOrFail($data['grant_id']), (string) $data['comment'],
                    ))),
            ])->label(__('admin.people.territorial_access'))->icon('heroicon-o-map')->button()->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $person = $this->person();
        $viewer = $this->viewer();
        $access = $this->access();
        $profile = PersonProfile::query()->find($person->id);
        $membership = OrgMembership::query()->with(['unit', 'manager'])->find($person->id);
        $isSelf = $viewer->person_id === $person->id;
        $seesPersonal = $profile !== null && $access->canSeeField($viewer, $person, 'people.fields.personal.read', $profile->personal_visibility);
        $seesSkills = $profile !== null && $access->canSeeField($viewer, $person, 'people.fields.skills.read', $profile->skills_visibility);
        $contactTypes = Options::catalog('contact_types');
        $org = app(OrgStructure::class);
        $seesAccess = $isSelf || in_array($viewer->person_id, $org->managerChain($person->id), true)
            || app(AuthorizationService::class)->can($viewer, 'access.simulate')
            || app(ManageTerritoryGrants::class)->mayManageFor($viewer, $person);
        $seesHistory = $isSelf || $this->may('people.update');

        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('name')->label(__('admin.fields.name'))->state($person->fullName())->weight('bold'),
                TextEntry::make('unit')->label(__('admin.org_units.singular'))->state($membership?->unit->name)->placeholder('—'),
                TextEntry::make('position')->label(__('admin.org_units.position'))->state($membership?->position)->placeholder('—'),
                TextEntry::make('manager')->label(__('admin.org_units.manager'))->state($membership?->manager?->fullName())->placeholder('—'),
                TextEntry::make('type')->label(__('admin.fields.person_type'))->badge()
                    ->state(Options::catalog('person_types')[$person->person_type] ?? $person->person_type),
                TextEntry::make('cover')->label(__('profiles.fields.cover'))->badge()->placeholder('—')
                    ->state($profile?->cover_code !== null ? (Options::catalog('profile_covers')[$profile->cover_code] ?? null) : null),
                TextEntry::make('bio')->label(__('profiles.fields.bio'))->state($profile?->bio)->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('profiles.fields.contacts'))->schema([
                RepeatableEntry::make('contacts')->hiddenLabel()->placeholder(__('admin.people.nothing_visible'))->columns(3)
                    ->state($access->visibleContacts($viewer, $person)->map(fn ($c): array => [
                        'type' => $contactTypes[$c->contact_type] ?? $c->contact_type,
                        'value' => $c->value,
                        'visibility' => $isSelf ? $c->visibility->label() : null,
                    ])->all())
                    ->schema([
                        TextEntry::make('type')->hiddenLabel(),
                        TextEntry::make('value')->hiddenLabel()->copyable(),
                        TextEntry::make('visibility')->hiddenLabel()->badge()->placeholder(''),
                    ]),
            ]),
            Section::make(__('admin.people.personal'))->columns(3)->visible($seesPersonal || $seesSkills)->schema([
                TextEntry::make('birth_date')->label(__('profiles.fields.birth_date'))->visible($seesPersonal)
                    ->state($profile?->birth_date?->isoFormat('LL'))->placeholder('—'),
                TextEntry::make('gender')->label(__('profiles.fields.gender'))->visible($seesPersonal)
                    ->state($profile?->gender !== null ? __('profiles.gender.'.$profile->gender) : null)->placeholder('—'),
                TextEntry::make('skills')->label(__('profiles.fields.skills'))->badge()->visible($seesSkills)->state($profile->skills ?? [])->placeholder('—'),
                TextEntry::make('interests')->label(__('profiles.fields.interests'))->badge()->visible($seesSkills)->state($profile->interests ?? [])->placeholder('—'),
                TextEntry::make('languages')->label(__('profiles.fields.languages'))->badge()->visible($seesSkills)->state($profile->languages ?? [])->placeholder('—'),
            ]),
            ...$this->crmSections(),
            Section::make(__('admin.people.territorial_access'))->visible($seesAccess)->collapsible()->schema([
                RepeatableEntry::make('territorial_access')->hiddenLabel()->placeholder(__('admin.people.no_territories'))->columns(3)
                    ->state(collect(app(TerritorialAccess::class)->explain($person->id))->map(fn (array $row): array => [
                        'territory' => $row['territory']->name(),
                        'origin' => $row['source'] === 'unit'
                            ? __('admin.people.from_unit', ['unit' => $row['unit']?->name])
                            : __('admin.people.granted_by', ['name' => $this->author((int) $row['grant']?->granted_by_user_id)]),
                        'until' => $row['grant']?->expires_at?->isoFormat('LL') ?? ($row['source'] === 'grant' ? __('admin.people.no_end') : ''),
                    ])->all())
                    ->schema([
                        TextEntry::make('territory')->hiddenLabel()->weight('bold'),
                        TextEntry::make('origin')->hiddenLabel(),
                        TextEntry::make('until')->hiddenLabel()->placeholder(''),
                    ]),
            ]),
            Section::make(__('admin.people.link_hints'))->collapsible()
                ->visible(fn (): bool => app(AuthorizationService::class)->scopeQuery($viewer, 'people.link_hints.read', AccountLinkHint::query())
                    ->where('existing_person_id', $person->id)->where('status', 'open')->exists())
                ->schema([
                    TextEntry::make('hints')->hiddenLabel()->badge()->color('warning')
                        ->state(fn (): array => app(AuthorizationService::class)->scopeQuery($viewer, 'people.link_hints.read', AccountLinkHint::query())
                            ->with('newPerson')->where('existing_person_id', $person->id)->where('status', 'open')->get()
                            ->map(fn (AccountLinkHint $h): string => $h->newPerson->fullName().' — '.collect($h->reasons)->map(fn ($r) => __('admin.link_hints.reason.'.$r))->implode(', '))->all()),
                ]),
            Section::make(__('admin.people.history'))->visible($seesHistory)->collapsed()->collapsible()->schema([
                RepeatableEntry::make('memberships')->label(__('admin.people.past_units'))->placeholder('—')->columns(3)
                    ->state(OrgMembershipHistory::query()->with('unit')->where('person_id', $person->id)->latest('left_at')->get()
                        ->map(fn (OrgMembershipHistory $h): array => ['unit' => $h->unit->name, 'from' => $h->joined_at->isoFormat('LL'), 'to' => $h->left_at->isoFormat('LL')])->all())
                    ->schema([TextEntry::make('unit')->hiddenLabel(), TextEntry::make('from')->hiddenLabel(), TextEntry::make('to')->hiddenLabel()]),
                RepeatableEntry::make('events')->label(__('admin.people.changes'))->placeholder('—')->columns(3)
                    ->state(JournalEntry::query()->where('subject_type', $person->getMorphClass())->where('subject_id', (string) $person->id)
                        ->where(fn ($q) => $q->where('event_type', 'like', 'org.%')->orWhere('event_type', 'like', 'access.territory.%'))
                        ->latest('id')->limit(30)->get()
                        ->map(fn (JournalEntry $e): array => [
                            'at' => $e->occurred_at->isoFormat('LLL'),
                            'event' => __('journal.events.'.$e->event_type),
                            'by' => $e->actor_user_id !== null ? $this->author($e->actor_user_id) : __('journal.actor_types.'.$e->actor_type->value),
                        ])->all())
                    ->schema([TextEntry::make('at')->hiddenLabel(), TextEntry::make('event')->hiddenLabel(), TextEntry::make('by')->hiddenLabel()]),
            ]),
        ]);
    }
}
