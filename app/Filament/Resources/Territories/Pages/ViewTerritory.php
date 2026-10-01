<?php

declare(strict_types=1);

namespace App\Filament\Resources\Territories\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Geo\Actions\ManageTerritories;
use App\Domain\Geo\Models\Territory;
use App\Domain\Geo\Models\TerritoryResponsible;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Filament\Resources\Territories\TerritoryResource;
use App\Filament\Support\Options;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;

class ViewTerritory extends ViewRecord
{
    protected static string $resource = TerritoryResource::class;

    private function territory(): Territory
    {
        $record = $this->getRecord();
        assert($record instanceof Territory);

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
        return app(AuthorizationService::class)->can($this->actor(), $code, $this->territory());
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')->label(__('admin.territories.edit'))
                ->visible(fn (): bool => $this->may('territories.manage'))
                ->fillForm(fn (): array => [
                    'description' => $this->territory()->description,
                    'aliases' => $this->territory()->search_aliases ?? [],
                    'is_active' => $this->territory()->is_active,
                ])
                ->schema([
                    Textarea::make('description')->label(__('admin.territories.description')),
                    TagsInput::make('aliases')->label(__('admin.territories.aliases')),
                    Toggle::make('is_active')->label(__('admin.catalogs.active')),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageTerritories::class)->update(
                    $this->actor(), $this->territory(), $data['description'] ?? null, array_values((array) ($data['aliases'] ?? [])), (bool) $data['is_active'],
                ))),
            Action::make('addChild')->label(__('admin.territories.add_child'))
                ->visible(fn (): bool => $this->may('territories.manage'))
                ->schema([
                    Select::make('level')->label(__('admin.territories.level'))->options(fn (): array => Options::catalog('territory_levels'))->required(),
                    TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageTerritories::class)->add(
                    $this->actor(), $this->territory(), (string) $data['level'], [app()->getLocale() => (string) $data['name']], app()->getLocale(),
                ))),
            Action::make('assignResponsible')->label(__('admin.territories.assign_responsible'))
                ->visible(fn (): bool => $this->may('territories.responsible.assign'))
                ->schema([
                    Select::make('person_id')->label(__('admin.fields.name'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => app(AuthorizationService::class)
                            ->scopeQuery($this->actor(), 'people.read', Person::query())
                            ->where(fn ($query) => $query->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                            ->limit(20)->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()])->all()),
                ])
                ->action(fn (array $data) => $this->run(fn () => app(ManageTerritories::class)->assignResponsible(
                    $this->actor(), $this->territory(), Person::query()->findOrFail($data['person_id']),
                ))),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('name_ro')->label('ro'),
                TextEntry::make('name_ru')->label('ru'),
                TextEntry::make('name_en')->label('en'),
                TextEntry::make('trail')->label(__('admin.territories.parent'))->state(fn (Territory $record): string => TerritoryResource::trail($record) ?: '—'),
                TextEntry::make('level')->label(__('admin.territories.level'))
                    ->formatStateUsing(fn (string $state): string => Options::catalog('territory_levels')[$state] ?? $state),
                TextEntry::make('code')->label(__('admin.catalogs.code'))->fontFamily('mono'),
                TextEntry::make('search_aliases')->label(__('admin.territories.aliases'))->badge()->placeholder('—'),
                TextEntry::make('coordinates')->label(__('admin.territories.coordinates'))->placeholder('—')
                    ->state(fn (Territory $record): ?string => $record->latitude !== null ? $record->latitude.', '.$record->longitude : null),
                TextEntry::make('description')->label(__('admin.territories.description'))->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('admin.territories.responsibles'))->schema([
                RepeatableEntry::make('responsibles')->hiddenLabel()->placeholder('—')
                    ->state(fn (Territory $record): array => TerritoryResponsible::query()->with('person')->where('territory_id', $record->id)->get()
                        ->map(fn (TerritoryResponsible $r): array => ['name' => $r->person->fullName(), 'since' => $r->assigned_at])->all())
                    ->columns(2)
                    ->schema([TextEntry::make('name')->hiddenLabel(), TextEntry::make('since')->hiddenLabel()->date()]),
            ]),
            Section::make(__('admin.territories.children'))->collapsible()->schema([
                TextEntry::make('children_list')->hiddenLabel()->placeholder('—')->badge()
                    ->state(fn (Territory $record): array => $record->children()->orderBy('sort_order')->limit(200)->get()->map(fn (Territory $t): string => $t->name())->all()),
            ]),
        ]);
    }
}
