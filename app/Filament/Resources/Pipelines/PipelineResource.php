<?php

declare(strict_types=1);

namespace App\Filament\Resources\Pipelines;

use App\Domain\CRM\Actions\ManagePipelines;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Models\PipelineStage;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Pipelines\Pages\ListPipelines;
use App\Filament\Support\NameInputs;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The pipeline constructor (ФО §6.9.2): pipelines and their stages are set up here, without code.
 */
class PipelineResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Pipeline::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.crm');
    }

    public static function getModelLabel(): string
    {
        return __('admin.pipelines.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.pipelines.plural');
    }

    public static function canViewAny(): bool
    {
        return static::allows('pipelines.manage');
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
     * @return list<Component>
     */
    public static function fields(): array
    {
        return [
            ...NameInputs::make(),
            Textarea::make('description')->label(__('admin.tasks.description'))->rows(2),
            Toggle::make('is_active')->label(__('admin.pipelines.active'))->default(true),
            Select::make('auto_enroll_types')->label(__('admin.pipelines.auto_enroll'))->multiple()
                ->options(fn (): array => Options::catalog('person_types'))->helperText(__('admin.pipelines.auto_enroll_hint')),
            Toggle::make('auto_assign_by_territory')->label(__('admin.pipelines.auto_assign'))->helperText(__('admin.pipelines.auto_assign_hint')),
            Repeater::make('stages')->label(__('admin.pipelines.stages'))->helperText(__('admin.pipelines.stages_hint'))
                ->minItems(1)->defaultItems(1)->columns(5)->reorderable()->schema([
                    Hidden::make('id'),
                    ...NameInputs::make(),
                    Select::make('kind')->label(__('admin.pipelines.kind'))->required()->default(PipelineStage::OPEN)
                        ->options(collect([PipelineStage::OPEN, PipelineStage::WON, PipelineStage::LOST])
                            ->mapWithKeys(fn (string $kind): array => [$kind => __('admin.pipelines.kinds.'.$kind)])->all()),
                    TextInput::make('sla_hours')->label(__('admin.pipelines.sla_hours'))->numeric()->integer()->minValue(1),
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?Pipeline $pipeline = null): void
    {
        $stages = array_map(fn (array $stage): array => [
            'id' => isset($stage['id']) ? (int) $stage['id'] : null,
            'names' => NameInputs::names($stage),
            'kind' => (string) ($stage['kind'] ?? PipelineStage::OPEN),
            'sla_hours' => filled($stage['sla_hours'] ?? null) ? (int) $stage['sla_hours'] : null,
        ], array_values((array) ($data['stages'] ?? [])));

        static::attempt(fn () => app(ManagePipelines::class)->save(static::actor(), [
            'names' => NameInputs::names($data),
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'auto_enroll_types' => array_values((array) ($data['auto_enroll_types'] ?? [])),
            'auto_assign_by_territory' => (bool) ($data['auto_assign_by_territory'] ?? false),
        ], app()->getLocale(), $stages, $pipeline), __('admin.saved'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->state(fn (Pipeline $record): string => $record->name())->weight('bold'),
                TextColumn::make('stages')->label(__('admin.pipelines.stages'))->wrap()
                    ->state(fn (Pipeline $record): string => $record->stages()->where('is_active', true)->get()->map(fn (PipelineStage $stage): string => $stage->name())->implode(' → ')),
                TextColumn::make('leads')->label(__('admin.leads.plural'))->state(fn (Pipeline $record): int => Lead::query()->where('pipeline_id', $record->id)->count()),
                IconColumn::make('is_active')->label(__('admin.pipelines.active'))->boolean(),
            ])
            ->recordActions([
                Action::make('edit')->label(__('admin.org_units.edit'))->icon('heroicon-o-pencil-square')
                    ->fillForm(fn (Pipeline $record): array => [
                        ...$record->only(['name_ro', 'name_ru', 'name_en', 'description', 'is_active', 'auto_enroll_types', 'auto_assign_by_territory']),
                        'stages' => $record->stages()->where('is_active', true)->get()
                            ->map(fn (PipelineStage $stage): array => $stage->only(['id', 'name_ro', 'name_ru', 'name_en', 'kind', 'sla_hours']))->all(),
                    ])
                    ->schema(static::fields())
                    ->action(fn (Pipeline $record, array $data) => static::save($data, $record)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPipelines::route('/')];
    }
}
