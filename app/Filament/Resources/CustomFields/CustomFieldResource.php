<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomFields;

use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Support\NameInputs;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Custom fields of a person card (ФО §5.1, §6.9.1): the administrator adds them without code.
 */
class CustomFieldResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = CustomField::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquaresPlus;

    protected static ?int $navigationSort = 70;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.crm');
    }

    public static function getModelLabel(): string
    {
        return __('admin.custom_fields.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.custom_fields.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('entity', CustomField::PERSON);
    }

    public static function canViewAny(): bool
    {
        return static::allows('custom_objects.types.manage');
    }

    public static function canCreate(): bool
    {
        return false;
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
     * @return array<string, string>
     */
    public static function types(): array
    {
        return collect(CustomField::TYPES)->mapWithKeys(fn (string $type): array => [$type => __('admin.custom_fields.types.'.$type)])->all();
    }

    /**
     * @return list<Component>
     */
    public static function fields(bool $creating): array
    {
        return [
            ...NameInputs::make(),
            Select::make('field_type')->label(__('admin.custom_fields.type'))->options(static::types())->required()->default('text')->live()
                ->disabled(! $creating)->helperText($creating ? null : __('admin.custom_fields.type_fixed')),
            Repeater::make('options')->label(__('admin.custom_fields.options'))->columns(3)->defaultItems(0)
                ->visible(fn (Get $get): bool => $get('field_type') === 'select')
                ->schema([
                    // The stored value of an option never changes, whatever its labels become.
                    Hidden::make('value'),
                    TextInput::make('ro')->label('RO')->required(),
                    TextInput::make('ru')->label('RU'),
                    TextInput::make('en')->label('EN'),
                ]),
            Select::make('applies_to')->label(__('admin.custom_fields.applies_to'))->multiple()
                ->options(fn (): array => Options::catalog('person_types'))->helperText(__('admin.custom_fields.applies_to_hint')),
            Toggle::make('is_active')->label(__('admin.custom_fields.active'))->default(true),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?CustomField $field = null): void
    {
        static::attempt(fn () => app(CustomFields::class)->saveDefinition(static::actor(), CustomField::PERSON, [
            'names' => NameInputs::names($data),
            'field_type' => (string) ($data['field_type'] ?? ($field !== null ? $field->field_type : 'text')),
            'options' => array_map(fn (array $option): array => array_filter([
                'value' => $option['value'] ?? null, 'ro' => $option['ro'] ?? null, 'ru' => $option['ru'] ?? null, 'en' => $option['en'] ?? null,
            ], fn ($value): bool => filled($value)), array_values((array) ($data['options'] ?? []))),
            'applies_to' => array_values((array) ($data['applies_to'] ?? [])),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ], app()->getLocale(), $field), __('admin.saved'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->state(fn (CustomField $record): string => $record->name())->weight('bold')
                    ->description(fn (CustomField $record): string => 'cf_'.$record->code),
                TextColumn::make('field_type')->label(__('admin.custom_fields.type'))->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => static::types()[$state] ?? $state),
                TextColumn::make('applies_to')->label(__('admin.custom_fields.applies_to'))->badge()->placeholder(__('admin.custom_fields.all_types'))
                    ->state(fn (CustomField $record): array => collect($record->applies_to ?? [])->map(fn (string $type): string => Options::catalog('person_types')[$type] ?? $type)->all()),
                IconColumn::make('is_active')->label(__('admin.custom_fields.active'))->boolean(),
            ])
            ->recordActions([
                Action::make('edit')->label(__('admin.org_units.edit'))->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (CustomField $record): array => $record->only(['name_ro', 'name_ru', 'name_en', 'field_type', 'options', 'applies_to', 'is_active']))
                    ->schema(static::fields(false))
                    ->action(fn (CustomField $record, array $data) => static::save($data, $record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomFields::route('/')];
    }
}
