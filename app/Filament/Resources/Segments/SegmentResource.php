<?php

declare(strict_types=1);

namespace App\Filament\Resources\Segments;

use App\Domain\CRM\Exports\Exports;
use App\Domain\CRM\Models\ExportBatch;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Segment;
use App\Domain\CRM\Segments;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Options;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Segments and saved filters (ФО §6.9.4). Everyone sees their own filters; shared segments — with segments.read.
 * The number of people is counted for the viewer, inside the viewer's own scope.
 */
class SegmentResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Segment::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.crm');
    }

    public static function getModelLabel(): string
    {
        return __('admin.segments.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.segments.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('segments.id', app(Segments::class)->visibleTo(static::actor())->select('segments.id'));
    }

    public static function canViewAny(): bool
    {
        return static::allows('people.read');
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
        $s = fn (string $key): string => __('admin.segments.'.$key);

        return [
            TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(150),
            Textarea::make('description')->label(__('admin.tasks.description'))->rows(2),
            Select::make('visibility')->label($s('visibility'))->required()->default(Segment::PERSONAL)
                ->options(fn (): array => array_filter([
                    Segment::PERSONAL => $s('personal'),
                    Segment::SHARED => static::allows('segments.manage') ? $s('shared') : null,
                ])),
            Section::make($s('criteria'))->statePath('criteria')->columns(2)->schema([
                Select::make('person_types')->label(__('admin.fields.person_type'))->multiple()->options(fn (): array => Options::catalog('person_types')),
                Select::make('source_code')->label(__('admin.people.source'))->options(fn (): array => Options::catalog('contact_sources')),
                Places::territory()->helperText($s('territory_hint')),
                Places::unit('unit_id'),
                TextInput::make('age_from')->label($s('age_from'))->numeric()->integer()->minValue(0)->maxValue(120),
                TextInput::make('age_to')->label($s('age_to'))->numeric()->integer()->minValue(0)->maxValue(120),
                Select::make('gender')->label(__('profiles.fields.gender'))->options(['female' => __('profiles.gender.female'), 'male' => __('profiles.gender.male')]),
                TagsInput::make('languages')->label(__('profiles.fields.languages')),
                Select::make('pipeline_id')->label(__('admin.leads.pipeline'))->options(fn (): array => LeadResource::pipelines(false))->live(),
                Select::make('stage_id')->label(__('admin.leads.stage'))
                    ->options(fn (Get $get): array => $get('pipeline_id') ? LeadResource::stages($get('pipeline_id')) : []),
                Select::make('lead_status')->label($s('lead_status'))
                    ->options(collect([Lead::OPEN, Lead::FROZEN, Lead::WON, Lead::LOST])->mapWithKeys(fn (string $status): array => [$status => __('crm.lead_statuses.'.$status)])->all()),
                Select::make('interaction_kind')->label($s('interaction_kind'))->options(fn (): array => Options::catalog('interaction_kinds')),
                TextInput::make('interaction_days')->label($s('interaction_days'))->numeric()->integer()->minValue(1),
                DatePicker::make('created_from')->label($s('created_from')),
                DatePicker::make('created_to')->label($s('created_to')),
                Toggle::make('include_archived')->label($s('include_archived')),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data, ?Segment $segment = null): void
    {
        static::attempt(fn () => app(Segments::class)->save(static::actor(), [
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'visibility' => (string) ($data['visibility'] ?? Segment::PERSONAL),
            'criteria' => (array) ($data['criteria'] ?? []),
        ], $segment), __('admin.saved'));
    }

    public static function mayChange(Segment $segment): bool
    {
        return $segment->owner_user_id === static::actor()->id
            || ($segment->visibility === Segment::SHARED && static::allows('segments.manage', $segment->owner->person));
    }

    public static function table(Table $table): Table
    {
        $s = fn (string $key): string => __('admin.segments.'.$key);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('owner.person'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->weight('bold')->searchable()
                    ->description(fn (Segment $record): ?string => $record->description),
                TextColumn::make('visibility')->label($s('visibility'))->badge()
                    ->formatStateUsing(fn (string $state): string => $s($state))
                    ->color(fn (string $state): string => $state === Segment::SHARED ? 'info' : 'gray'),
                TextColumn::make('owner')->label($s('owner'))->state(fn (Segment $record): string => $record->owner->person->fullName()),
                TextColumn::make('people')->label($s('people'))->alignEnd()
                    ->state(fn (Segment $record): int => app(Segments::class)->people(static::actor(), $record)->count()),
            ])
            ->recordActions([
                Action::make('open')->label($s('open'))->icon('heroicon-o-users')
                    ->url(fn (Segment $record): string => PersonResource::getUrl('index', ['filters' => ['segment' => ['value' => $record->id]]])),
                ActionGroup::make([
                    Action::make('edit')->label(__('admin.org_units.edit'))->icon('heroicon-o-pencil-square')
                        ->visible(fn (Segment $record): bool => static::mayChange($record))
                        ->fillForm(fn (Segment $record): array => $record->only(['name', 'description', 'visibility', 'criteria']))
                        ->schema(static::fields())
                        ->action(fn (Segment $record, array $data) => static::save($data, $record)),
                    Action::make('tasks')->label($s('create_tasks'))->icon('heroicon-o-clipboard-document-check')
                        ->visible(fn (): bool => TaskResource::canCreate())
                        ->modalDescription(fn (Segment $record): string => __('admin.segments.create_tasks_hint', [
                            'count' => app(Segments::class)->people(static::actor(), $record)->count(), 'limit' => Segments::MASS_TASK_LIMIT,
                        ]))
                        ->schema([
                            TextInput::make('title')->label(__('admin.tasks.title'))->required()->maxLength(255),
                            Select::make('type_code')->label(__('admin.tasks.type'))->options(fn (): array => Options::catalog('task_types'))->required()->default('call'),
                            DateTimePicker::make('due_at')->label(__('admin.tasks.due'))->seconds(false),
                            Select::make('assignees')->label(__('admin.tasks.assignees'))->multiple()->searchable()->required()
                                ->getSearchResultsUsing(fn (string $search): array => TaskResource::assignableSearch($search)),
                        ])
                        ->action(fn (Segment $record, array $data) => static::attempt(fn () => app(Segments::class)->createTasks(static::actor(), $record, [
                            'title' => (string) $data['title'], 'type_code' => (string) $data['type_code'], 'due_at' => $data['due_at'] ?? null,
                        ], array_map('intval', (array) $data['assignees'])), __('admin.segments.tasks_created'))),
                    Action::make('export')->label(__('admin.export.action'))->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn (): bool => static::allows('people.export'))
                        ->schema([Select::make('format')->label(__('admin.export.format'))->options(['xlsx' => 'Excel (XLSX)', 'csv' => 'CSV'])->default('xlsx')->required()])
                        ->action(function (Segment $record, array $data) {
                            $batch = null;
                            static::attempt(function () use ($record, $data, &$batch): void {
                                $batch = app(Exports::class)->request(static::actor(), Exports::PEOPLE, (string) $data['format'], $record->criteria);
                            }, null);

                            return $batch instanceof ExportBatch && $batch->status === ExportBatch::READY ? redirect()->route('exports.download', $batch) : null;
                        }),
                    Action::make('delete')->label($s('delete'))->icon('heroicon-o-trash')->color('danger')
                        ->visible(fn (Segment $record): bool => static::mayChange($record))
                        ->requiresConfirmation()
                        ->action(fn (Segment $record) => static::attempt(fn () => app(Segments::class)->delete(static::actor(), $record), __('admin.saved'))),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSegments::route('/')];
    }
}
