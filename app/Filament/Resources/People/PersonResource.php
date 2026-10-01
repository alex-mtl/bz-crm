<?php

declare(strict_types=1);

namespace App\Filament\Resources\People;

use App\Domain\CRM\SegmentQuery;
use App\Domain\CRM\Segments;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\Organization\Models\OrgMembership;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\People\Pages\CreatePerson;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Resources\People\Pages\ViewPerson;
use App\Filament\Support\CustomFieldInputs;
use App\Filament\Support\Options;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * People (ФО §6.3): the open layer within the viewer's scope (people.read). Confidential layers are separate
 * entities opened from the card, each view journaled.
 */
class PersonResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Person::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'people';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.organization');
    }

    public static function getModelLabel(): string
    {
        return __('admin.people.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.people.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scoped(parent::getEloquentQuery(), 'people.read');
    }

    public static function canViewAny(): bool
    {
        return static::allows('people.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('people.read', $record);
    }

    public static function canCreate(): bool
    {
        return static::allows('people.create');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * The card itself (ФО §6.9.1): who the person is and where they belong. Used for creating and for editing.
     *
     * @return list<Component>
     */
    public static function cardFields(): array
    {
        return [
            TextInput::make('first_name')->label(__('identity.fields.first_name'))->required()->maxLength(100),
            TextInput::make('last_name')->label(__('identity.fields.last_name'))->maxLength(100),
            Select::make('person_type')->label(__('admin.fields.person_type'))->options(fn (): array => Options::catalog('person_types'))
                ->required()->default('supporter')->live(),
            Select::make('preferred_locale')->label(__('identity.fields.locale'))->options(fn (): array => Options::locales())->required()->default('ro'),
            TextInput::make('email')->label(__('identity.fields.email'))->email()->maxLength(255),
            TextInput::make('phone')->label(__('admin.people.phone'))->tel()->maxLength(32),
            Places::territory()->helperText(__('admin.people.territory_hint')),
            Places::unit('responsible_unit_id')->label(__('admin.people.responsible_unit'))->helperText(__('admin.people.responsible_unit_hint'))
                ->default(fn (): ?int => app(OrgStructure::class)->unitOf(static::actor()->person_id)?->id),
            Select::make('source_code')->label(__('admin.people.source'))->options(fn (): array => Options::catalog('contact_sources')),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema(static::cardFields()),
            Section::make(__('admin.custom_fields.plural'))->columns(2)
                ->visible(fn (Get $get): bool => CustomFieldInputs::for(CustomField::PERSON, $get('person_type')) !== [])
                ->schema(fn (Get $get): array => CustomFieldInputs::for(CustomField::PERSON, $get('person_type'))),
        ]);
    }

    public static function table(Table $table): Table
    {
        $membership = fn (Person $p): ?OrgMembership => OrgMembership::query()->with(['unit', 'manager'])->find($p->id);

        return $table
            ->defaultSort('last_name')
            ->columns([
                TextColumn::make('last_name')->label(__('admin.fields.name'))
                    ->formatStateUsing(fn (Person $record): string => $record->fullName())
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $w) => $w
                        ->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
                    ->sortable(),
                TextColumn::make('unit')->label(__('admin.org_units.singular'))->placeholder('—')
                    ->state(fn (Person $record): ?string => $membership($record)?->unit->name),
                TextColumn::make('position')->label(__('admin.org_units.position'))->placeholder('—')
                    ->state(fn (Person $record): ?string => $membership($record)?->position),
                TextColumn::make('manager')->label(__('admin.org_units.manager'))->placeholder('—')
                    ->state(fn (Person $record): ?string => $membership($record)?->manager?->fullName()),
                TextColumn::make('person_type')->label(__('admin.fields.person_type'))->badge()
                    ->formatStateUsing(fn (string $state): string => Options::catalog('person_types')[$state] ?? $state),
                TextColumn::make('territory_id')->label(__('admin.territories.singular'))->placeholder('—')->toggleable()
                    ->formatStateUsing(fn ($state): ?string => Places::territoryName($state)),
                TextColumn::make('archived_at')->label(__('admin.people.archived'))->date()->placeholder('')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('unit')->label(__('admin.org_units.singular'))
                    ->options(fn (): array => OrgUnit::query()->active()->orderBy('path')->get()
                        ->mapWithKeys(fn (OrgUnit $u): array => [$u->id => str_repeat('— ', $u->depth).$u->name])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $w, $unit) => $w
                        ->where(fn (Builder $any) => $any
                            ->whereIn('id', OrgMembership::query()->where('org_unit_id', $unit)->select('person_id'))
                            ->orWhere('responsible_unit_id', $unit)))),
                SelectFilter::make('person_type')->label(__('admin.fields.person_type'))->options(fn (): array => Options::catalog('person_types')),
                SelectFilter::make('source_code')->label(__('admin.people.source'))->options(fn (): array => Options::catalog('contact_sources')),
                // ФО §6.9.4: a saved filter or a shared segment narrows the list; the scope of the viewer still applies.
                SelectFilter::make('segment')->label(__('admin.segments.singular'))
                    ->options(fn (): array => app(Segments::class)->visibleTo(static::actor())->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): void {
                        $segment = filled($data['value'] ?? null) ? app(Segments::class)->visibleTo(static::actor())->find($data['value']) : null;
                        if ($segment !== null) {
                            $query->whereIn('people.id', app(SegmentQuery::class)->build($segment->criteria)->select('people.id'));
                        }
                    }),
                // By default the list shows working cards; archived and merged ones are a click away, never lost.
                TernaryFilter::make('archived')->label(__('admin.people.archived'))
                    ->placeholder(__('admin.people.only_active'))->trueLabel(__('admin.people.only_archived'))->falseLabel(__('admin.people.with_archived'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->whereNull('archived_at'),
                    ),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
            'create' => CreatePerson::route('/create'),
            'view' => ViewPerson::route('/{record}'),
        ];
    }
}
