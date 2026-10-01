<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrgUnits\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Actions\ManageMembership;
use App\Domain\Organization\Actions\ManageOrgUnits;
use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use App\Filament\Resources\OrgUnits\OrgUnitResource;
use App\Filament\Support\PersonSearch;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;

class ViewOrgUnit extends ViewRecord
{
    protected static string $resource = OrgUnitResource::class;

    private function unit(): OrgUnit
    {
        $record = $this->getRecord();
        assert($record instanceof OrgUnit);

        return $record;
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function may(string $code, ?object $subject = null): bool
    {
        return app(AuthorizationService::class)->can($this->actor(), $code, $subject ?? $this->unit());
    }

    private function run(callable $action): void
    {
        try {
            $action();
            Notification::make()->title(__('admin.saved'))->success()->send();
            $this->refreshFormData([]);
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $units = fn (): array => OrgUnit::query()->active()->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u): array => [$u->id => str_repeat('— ', $u->depth).$u->name])->all();

        return [
            Action::make('place')->label(__('admin.org_units.place'))
                ->visible(fn (): bool => $this->may('people.transfer'))
                ->schema([
                    Select::make('person_id')->label(__('admin.fields.name'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, fn ($query) => $query->whereNotIn('id', OrgMembership::query()->select('person_id')), activeUsersOnly: true))
                        ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                    TextInput::make('position')->label(__('admin.org_units.position'))->maxLength(150),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageMembership::class)->place(
                    $this->actor(), Person::query()->findOrFail($data['person_id']), $this->unit(), $data['position'] ?? null,
                ))),
            ActionGroup::make([
                Action::make('edit')->label(__('admin.org_units.edit'))
                    ->visible(fn (): bool => $this->may('org_units.manage'))
                    ->fillForm(fn (): array => ['name' => $this->unit()->name, 'description' => $this->unit()->description])
                    ->schema([
                        TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
                        Textarea::make('description')->label(__('admin.territories.description')),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageOrgUnits::class)->update($this->actor(), $this->unit(), (string) $data['name'], $data['description'] ?? null))),
                Action::make('territories')->label(__('admin.org_units.set_territories'))
                    ->visible(fn (): bool => $this->may('org_units.territories.manage'))
                    ->fillForm(fn (): array => ['territories' => $this->unit()->territories()->pluck('territories.id')->all()])
                    ->schema([
                        Select::make('territories')->label(__('admin.org_units.territories'))->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Territory::query()->search($search)->limit(30)->get()
                                ->mapWithKeys(fn (Territory $t): array => [$t->id => $t->name()])->all())
                            ->getOptionLabelsUsing(fn (array $values): array => Territory::query()->whereKey($values)->get()
                                ->mapWithKeys(fn (Territory $t): array => [$t->id => $t->name()])->all()),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageOrgUnits::class)->setTerritories(
                        $this->actor(), $this->unit(), array_map('intval', (array) ($data['territories'] ?? [])),
                    ))),
                Action::make('head')->label(__('admin.org_units.assign_head'))
                    ->visible(fn (): bool => $this->may('org_units.assign_head'))
                    ->schema([
                        Select::make('person_id')->label(__('admin.org_units.head'))
                            ->options(fn (): array => OrgMembership::query()->with('person')->where('org_unit_id', $this->unit()->id)->get()
                                ->mapWithKeys(fn (OrgMembership $m): array => [$m->person_id => $m->person->fullName()])->all()),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageMembership::class)->assignHead(
                        $this->actor(), $this->unit(), isset($data['person_id']) ? Person::query()->find($data['person_id']) : null,
                    ))),
                Action::make('move')->label(__('admin.org_units.move'))
                    ->visible(fn (): bool => $this->may('org_units.manage'))
                    ->schema([Select::make('parent_id')->label(__('admin.org_units.parent'))->options($units)])
                    ->action(fn (array $data) => $this->run(fn () => app(ManageOrgUnits::class)->move(
                        $this->actor(), $this->unit(), isset($data['parent_id']) ? OrgUnit::query()->find($data['parent_id']) : null,
                    ))),
                Action::make('archive')->label(__('admin.org_units.archive'))->color('danger')->requiresConfirmation()
                    ->visible(fn (): bool => $this->unit()->archived_at === null && $this->may('org_units.manage'))
                    ->action(fn () => $this->run(fn () => app(ManageOrgUnits::class)->archive($this->actor(), $this->unit()))),
            ])->button()->label(__('admin.users.actions')),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('name')->label(__('admin.fields.name')),
                TextEntry::make('parent.name')->label(__('admin.org_units.parent'))->placeholder('—'),
                TextEntry::make('head')->label(__('admin.org_units.head'))->placeholder('—')
                    ->state(fn (OrgUnit $record): ?string => $record->head?->fullName()),
                TextEntry::make('territories')->label(__('admin.org_units.territories'))->badge()->placeholder(__('admin.org_units.no_territories'))
                    ->state(fn (OrgUnit $record): array => $record->territories()->get()->map(fn ($t): string => $t->name())->all()),
                TextEntry::make('children')->label(__('admin.org_units.children'))->badge()->placeholder('—')
                    ->state(fn (OrgUnit $record): array => $record->children()->active()->pluck('name')->all()),
                TextEntry::make('description')->label(__('admin.territories.description'))->placeholder('—'),
            ]),
        ]);
    }
}
