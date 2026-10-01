<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads;

use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Models\PipelineStage;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Leads\Pages\CreateLead;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Leads (ФО §6.9.2): the list shows exactly what pipelines.read allows — in SQL, so search and counters agree.
 */
class LeadResource extends Resource
{
    use ChecksPermissions;

    protected static ?string $model = Lead::class;

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.crm');
    }

    public static function getModelLabel(): string
    {
        return __('admin.leads.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.leads.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return static::scoped(parent::getEloquentQuery(), 'pipelines.read');
    }

    public static function canViewAny(): bool
    {
        return static::allows('pipelines.read');
    }

    public static function canView(Model $record): bool
    {
        return static::allows('pipelines.read', $record);
    }

    public static function canCreate(): bool
    {
        return static::allows('leads.create');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            Lead::WON => 'success', Lead::LOST => 'danger', Lead::FROZEN => 'warning', default => 'primary',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function pipelines(bool $activeOnly = true): array
    {
        return Pipeline::query()->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (Pipeline $pipeline): array => [$pipeline->id => $pipeline->name()])->all();
    }

    /**
     * @return array<int, string>
     */
    public static function stages(mixed $pipelineId, ?string $kind = null): array
    {
        return PipelineStage::query()->where('pipeline_id', $pipelineId)->where('is_active', true)
            ->when($kind !== null, fn (Builder $q) => $q->where('kind', $kind))->orderBy('sort_order')->get()
            ->mapWithKeys(fn (PipelineStage $stage): array => [$stage->id => $stage->name()])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                Select::make('person_id')->label(__('admin.leads.person'))->searchable()->required()
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, fn ($query) => $query->whereNull('archived_at')))
                    ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                Select::make('pipeline_id')->label(__('admin.leads.pipeline'))->options(fn (): array => static::pipelines())->required()->live(),
                Select::make('stage_id')->label(__('admin.leads.stage'))->helperText(__('admin.leads.stage_hint'))
                    ->options(fn (Get $get): array => $get('pipeline_id') ? static::stages($get('pipeline_id'), PipelineStage::OPEN) : []),
                Select::make('responsible_person_id')->label(__('admin.leads.responsible'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                    ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                TextInput::make('title')->label(__('admin.leads.title'))->maxLength(255)->columnSpanFull(),
                Select::make('source_code')->label(__('admin.people.source'))->options(fn (): array => Options::catalog('contact_sources')),
            ]),
            Section::make(__('admin.tasks.placement'))->columns(2)->collapsed()->schema([
                Places::unit()->helperText(__('admin.leads.unit_hint')),
                Places::territory()->helperText(__('admin.leads.territory_hint')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['person', 'pipeline', 'stage', 'responsible']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('person.last_name')->label(__('admin.leads.person'))
                    ->formatStateUsing(fn (Lead $record): string => $record->person->fullName())
                    ->description(fn (Lead $record): ?string => $record->title)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('person', fn (Builder $p) => $p
                        ->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))),
                TextColumn::make('pipeline_id')->label(__('admin.leads.pipeline'))->formatStateUsing(fn (Lead $record): string => $record->pipeline->name()),
                TextColumn::make('stage_id')->label(__('admin.leads.stage'))->formatStateUsing(fn (Lead $record): string => $record->stage->name())
                    ->color(fn (Lead $record): ?string => $record->isStageOverdue() ? 'danger' : null)
                    ->description(fn (Lead $record): ?string => $record->isStageOverdue() ? __('admin.leads.stage_overdue') : null),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('crm.lead_statuses.'.$state))
                    ->color(fn (string $state): string => static::statusColor($state))
                    ->description(fn (Lead $record): ?string => $record->status === Lead::FROZEN ? $record->frozen_until?->isoFormat('LL') : null),
                TextColumn::make('responsible_person_id')->label(__('admin.leads.responsible'))->placeholder('—')
                    ->formatStateUsing(fn (Lead $record): ?string => $record->responsible?->fullName()),
                TextColumn::make('territory_id')->label(__('admin.territories.singular'))->placeholder('—')->toggleable()
                    ->formatStateUsing(fn ($state): ?string => Places::territoryName($state)),
                TextColumn::make('stage_entered_at')->label(__('admin.leads.in_stage_since'))->since()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('pipeline_id')->label(__('admin.leads.pipeline'))->options(fn (): array => static::pipelines(false)),
                SelectFilter::make('status')->label(__('admin.fields.status'))->multiple()
                    ->options(collect([Lead::OPEN, Lead::FROZEN, Lead::WON, Lead::LOST])->mapWithKeys(fn (string $s): array => [$s => __('crm.lead_statuses.'.$s)])->all()),
                SelectFilter::make('mine')->label(__('admin.leads.mine'))->options(['1' => __('admin.yes')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $w) => $w->where('responsible_person_id', static::actor()->person_id))),
                ...static::deadlineFilters(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    /**
     * @return list<SelectFilter>
     */
    public static function deadlineFilters(): array
    {
        return [
            SelectFilter::make('stage_overdue')->label(__('admin.leads.stage_overdue'))->options(['1' => __('admin.yes')])
                ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $w) => $w
                    ->where('status', Lead::OPEN)->whereNotNull('stage_due_at')->where('stage_due_at', '<', now()))),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
            'create' => CreateLead::route('/create'),
            'view' => ViewLead::route('/{record}'),
        ];
    }
}
