<?php

declare(strict_types=1);

namespace App\Filament\Resources\Houses\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Models\Appeal;
use App\Domain\Geo\Actions\ManageAssignments;
use App\Domain\Geo\Actions\ManageHouses;
use App\Domain\Geo\Actions\RecordVisits;
use App\Domain\Geo\CanvassSummary;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\FieldNotes;
use App\Domain\Geo\FieldSettings;
use App\Domain\Geo\Models\Apartment;
use App\Domain\Geo\Models\ApartmentNote;
use App\Domain\Geo\Models\FieldAssignment;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Visit;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Filament\Resources\Houses\HouseResource;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The card of a house (ФО §6.11): the address, who answers for it, the flats with the status of the contact,
 * the history of visits, the notes the reader may read, the summary. The page asks; the domain decides.
 */
class ViewHouse extends ViewRecord
{
    protected static string $resource = HouseResource::class;

    protected string $view = 'filament.houses.view';

    private function house(): House
    {
        $record = $this->getRecord();
        assert($record instanceof House);

        return $record;
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function may(string $code): bool
    {
        return app(FieldAccess::class)->can($this->actor(), $code, $this->house());
    }

    public function getTitle(): string
    {
        return $this->house()->label();
    }

    private function attempt(callable $action, ?string $success = null): bool
    {
        try {
            $action();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

            return false;
        }
        $this->getRecord()->refresh();
        Notification::make()->title($success ?? __('admin.saved'))->success()->send();

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $house = $this->house()->loadMissing(['address.street', 'territory', 'apartments.lastVisitor']);
        $actor = $this->actor();
        $authorization = app(AuthorizationService::class);
        $statuses = CatalogItem::query()->ofCatalog('canvass_statuses')->get()->keyBy('code');

        return [
            'house' => $house,
            'type' => Options::catalog('house_types')[$house->type_code] ?? $house->type_code,
            'figures' => app(CanvassSummary::class)->forHouse($house),
            'statuses' => $statuses,
            'entrances' => $house->apartments->groupBy(fn (Apartment $apartment): int => (int) $apartment->entrance)->sortKeys(),
            'assignments' => FieldAssignment::query()->current()->with('person')->where(fn (Builder $where) => $where
                ->where('house_id', $house->id)
                ->orWhereIn('territory_id', [...$house->territory->ancestorIds(), $house->territory_id]))->orderBy('id')->get(),
            'mayManage' => $this->may('geo.houses.manage'),
            'mayAssign' => $this->may('geo.assignments.manage'),
            'mayVisit' => $this->may('geo.visits.create') && ! $house->isArchived(),
            // The history of visits and the notes: each under its own right.
            'visits' => $this->may('geo.visits.read')
                ? Visit::query()->with(['person', 'apartment'])->where('house_id', $house->id)->orderByDesc('visited_at')->orderByDesc('id')->limit(40)->get()
                : null,
            'notes' => app(FieldNotes::class)->visibleTo($actor, $house)->with(['author', 'apartment'])->orderByDesc('id')->limit(40)->get(),
            'appeals' => $authorization->scopeQuery($actor, 'appeals.read', Appeal::query())->whereIn('appeals.id', $house->appeals()->select('appeals.id'))->get(),
            'map' => $house->latitude !== null ? [
                'tiles' => app(FieldSettings::class)->map(),
                'point' => [(float) $house->latitude, (float) $house->longitude],
            ] : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);
        $houses = app(ManageHouses::class);

        return [
            Action::make('assign')->label($g('assign'))->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => $this->may('geo.assignments.manage') && ! $this->house()->isArchived())
                ->schema([
                    Select::make('person_id')->label($g('agitator'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                        ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value))
                        ->helperText($g('assign_hint')),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => app(ManageAssignments::class)
                    ->assignHouse($this->actor(), $this->house(), Person::query()->findOrFail($data['person_id'])))),
            ActionGroup::make([
                Action::make('edit')->label($g('edit'))->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (): array => $this->house()->only(['territory_id', 'entrances', 'floors', 'residents_count', 'latitude', 'longitude', 'description']))
                    ->schema(HouseResource::fields(false))
                    ->action(fn (array $data) => $this->attempt(fn () => $houses->update($this->actor(), $this->house(), $data))),
                Action::make('addApartments')->label($g('add_apartments'))->icon('heroicon-o-squares-plus')
                    ->schema([
                        TextInput::make('from')->label($g('from_number'))->numeric()->minValue(1)->required(),
                        TextInput::make('to')->label($g('to_number'))->numeric()->minValue(1)->required(),
                        TextInput::make('entrance')->label($g('entrance'))->numeric()->minValue(1),
                        TextInput::make('floor')->label($g('floor'))->numeric(),
                    ])
                    ->action(fn (array $data) => $this->attempt(fn () => $houses->addApartments(
                        $this->actor(), $this->house(), (int) $data['from'], (int) $data['to'],
                        filled($data['entrance'] ?? null) ? (int) $data['entrance'] : null, filled($data['floor'] ?? null) ? (int) $data['floor'] : null,
                    ))),
                Action::make('archive')->label(fn (): string => $this->house()->isArchived() ? $g('restore') : $g('archive'))
                    ->icon('heroicon-o-archive-box')->color('danger')->requiresConfirmation()
                    ->action(fn () => $this->attempt(fn () => $houses->archive($this->actor(), $this->house(), ! $this->house()->isArchived()))),
            ])->label($g('manage'))->icon('heroicon-o-cog-6-tooth')->button()->color('gray')
                ->visible(fn (): bool => $this->may('geo.houses.manage')),
            Action::make('linkAppeal')->label($g('link_appeal'))->icon('heroicon-o-inbox-arrow-down')->color('gray')
                ->visible(fn (): bool => app(AuthorizationService::class)->can($this->actor(), 'appeals.read'))
                ->schema([
                    Select::make('appeal_id')->label($g('appeal'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => app(AuthorizationService::class)
                            ->scopeQuery($this->actor(), 'appeals.read', Appeal::query())
                            ->where(fn (Builder $where) => $where->where('title', 'like', "%{$search}%")->orWhere('number', 'like', "%{$search}%"))
                            ->orderByDesc('id')->limit(25)->get()
                            ->mapWithKeys(fn (Appeal $appeal): array => [$appeal->id => $appeal->number.' · '.$appeal->title])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Appeal::query()->find($value)?->title),
                ])
                ->action(fn (array $data) => $this->attempt(fn () => $houses->linkAppeal($this->actor(), $this->house(), Appeal::query()->findOrFail($data['appeal_id'])))),
        ];
    }

    /**
     * The result of a visit, recorded from the panel — the same action as the phone at the entrance uses.
     */
    public function visitAction(): Action
    {
        $g = fn (string $key): string => __('geo.ui.'.$key);
        $statuses = fn (): Collection => CatalogItem::query()->ofCatalog('canvass_statuses')->selectable()->get()
            ->filter(fn (CatalogItem $status): bool => (bool) $status->property('visited'));

        return Action::make('visit')->label($g('record_visit'))
            ->modalHeading(fn (array $arguments): string => $g('apartment').' '.Apartment::query()->whereKey($arguments['apartment'] ?? 0)->value('number'))
            ->schema([
                Select::make('status_code')->label($g('result'))->required()->live()
                    ->options(fn (): array => $statuses()->mapWithKeys(fn (CatalogItem $status): array => [$status->code => $status->name()])->all()),
                DatePicker::make('next_visit_on')->label($g('next_visit'))->minDate(now()->startOfDay())
                    ->visible(fn (Get $get): bool => (bool) $statuses()->firstWhere('code', $get('status_code'))?->property('retry')),
                Toggle::make('create_task')->label($g('create_task'))
                    ->visible(fn (Get $get): bool => filled($get('next_visit_on'))),
                Textarea::make('note')->label($g('note'))->rows(3)->maxLength(RecordVisits::MAX_NOTE),
                Select::make('note_visibility')->label($g('note_visibility'))
                    ->options([ApartmentNote::TEAM => $g('visibility_team'), ApartmentNote::PERSONAL => $g('visibility_personal')])
                    ->default(fn (): string => app(FieldSettings::class)->defaultNoteVisibility())->selectablePlaceholder(false),
            ])
            ->action(fn (array $data, array $arguments) => $this->attempt(fn () => app(RecordVisits::class)->record(
                $this->actor(), Apartment::query()->where('house_id', $this->house()->id)->findOrFail($arguments['apartment'] ?? 0), [
                    'status_code' => (string) $data['status_code'],
                    'note' => $data['note'] ?? null,
                    'note_visibility' => $data['note_visibility'] ?? null,
                    'next_visit_on' => $data['next_visit_on'] ?? null,
                    'create_task' => (bool) ($data['create_task'] ?? false),
                ],
            ), $g('visit_saved')));
    }

    public function endAssignmentAction(): Action
    {
        return Action::make('endAssignment')->label(__('geo.ui.end_assignment'))->requiresConfirmation()->color('danger')
            ->action(fn (array $arguments) => $this->attempt(fn () => app(ManageAssignments::class)
                ->end($this->actor(), FieldAssignment::query()->findOrFail($arguments['assignment'] ?? 0))));
    }

    public function removeApartmentAction(): Action
    {
        return Action::make('removeApartment')->label(__('geo.ui.remove_apartment'))->requiresConfirmation()->color('danger')
            ->action(fn (array $arguments) => $this->attempt(fn () => app(ManageHouses::class)
                ->removeApartment($this->actor(), Apartment::query()->where('house_id', $this->house()->id)->findOrFail($arguments['apartment'] ?? 0))));
    }

    public function unlinkAppealAction(): Action
    {
        return Action::make('unlinkAppeal')->label(__('geo.ui.unlink'))->requiresConfirmation()
            ->action(fn (array $arguments) => $this->attempt(fn () => app(ManageHouses::class)
                ->unlinkAppeal($this->actor(), $this->house(), Appeal::query()->findOrFail($arguments['appeal'] ?? 0))));
    }
}
