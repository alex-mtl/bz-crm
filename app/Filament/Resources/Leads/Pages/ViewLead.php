<?php

declare(strict_types=1);

namespace App\Filament\Resources\Leads\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Actions\RecordInteraction;
use App\Domain\CRM\Models\Interaction;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\LeadStageHistory;
use App\Domain\CRM\Models\PipelineStage;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

class ViewLead extends ViewRecord
{
    protected static string $resource = LeadResource::class;

    public function getTitle(): string
    {
        return $this->lead()->title ?? $this->lead()->person->fullName();
    }

    private function lead(): Lead
    {
        $record = $this->getRecord();
        assert($record instanceof Lead);

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
        return app(AuthorizationService::class)->can($this->actor(), $code, $this->lead());
    }

    private function run(callable $action): void
    {
        try {
            $action();
            Notification::make()->title(__('admin.saved'))->success()->send();
            $this->record = $this->lead()->fresh() ?? $this->lead();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $l = fn (string $key): string => __('admin.leads.'.$key);
        $leads = fn (): ManageLeads => app(ManageLeads::class);
        $note = fn (): Textarea => Textarea::make('note')->label($l('note'))->rows(2);

        return [
            Action::make('move')->label($l('move'))->icon('heroicon-o-arrow-right')
                ->visible(fn (): bool => $this->lead()->status === Lead::OPEN && $this->may('pipelines.write'))
                ->schema([
                    Select::make('stage_id')->label($l('stage'))->required()
                        ->options(fn (): array => PipelineStage::query()->where('pipeline_id', $this->lead()->pipeline_id)->where('is_active', true)
                            ->where('kind', '!=', PipelineStage::LOST)->whereKeyNot($this->lead()->stage_id)->orderBy('sort_order')->get()
                            ->mapWithKeys(fn (PipelineStage $stage): array => [$stage->id => $stage->name()])->all()),
                    $note(),
                ])
                ->action(fn (array $data) => $this->run(fn () => $leads()->move($this->actor(), $this->lead(), PipelineStage::query()->findOrFail($data['stage_id']), $data['note'] ?? null))),
            ActionGroup::make([
                Action::make('interaction')->label(__('admin.crm.add_interaction'))->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn (): bool => app(AuthorizationService::class)->can($this->actor(), 'crm.interactions.create', $this->lead()->person))
                    ->schema([
                        Select::make('kind_code')->label(__('admin.crm.interaction_kind'))->options(fn (): array => Options::catalog('interaction_kinds'))->required()->default('call'),
                        Select::make('direction')->label(__('admin.crm.direction'))->options(['out' => __('crm.directions.out'), 'in' => __('crm.directions.in')]),
                        Textarea::make('summary')->label(__('admin.crm.summary'))->rows(3),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => app(RecordInteraction::class)($this->actor(), $this->lead()->person, (string) $data['kind_code'], [
                        'summary' => $data['summary'] ?? null, 'direction' => $data['direction'] ?? null,
                    ], $this->lead()))),
                Action::make('assign')->label($l('assign'))->icon('heroicon-o-user')
                    ->visible(fn (): bool => $this->may('leads.assign'))
                    ->fillForm(fn (): array => ['responsible_person_id' => $this->lead()->responsible_person_id])
                    ->schema([
                        Select::make('responsible_person_id')->label($l('responsible'))->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, activeUsersOnly: true))
                            ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                    ])
                    ->action(fn (array $data) => $this->run(fn () => $leads()->assign($this->actor(), $this->lead(), isset($data['responsible_person_id']) ? (int) $data['responsible_person_id'] : null))),
                Action::make('freeze')->label($l('freeze'))->icon('heroicon-o-pause')
                    ->visible(fn (): bool => $this->lead()->status === Lead::OPEN && $this->may('leads.close'))
                    ->schema([DatePicker::make('until')->label($l('frozen_until'))->required()->minDate(now()->addDay()), $note()])
                    ->action(fn (array $data) => $this->run(fn () => $leads()->freeze($this->actor(), $this->lead(), Carbon::parse($data['until']), $data['note'] ?? null))),
                Action::make('lose')->label($l('lose'))->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (): bool => in_array($this->lead()->status, [Lead::OPEN, Lead::FROZEN], true) && $this->may('leads.close'))
                    ->schema([Select::make('reason')->label($l('loss_reason'))->options(fn (): array => Options::catalog('lead_loss_reasons'))->required(), $note()])
                    ->action(fn (array $data) => $this->run(fn () => $leads()->lose($this->actor(), $this->lead(), (string) $data['reason'], $data['note'] ?? null))),
                Action::make('reopen')->label($l('reopen'))->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (): bool => $this->lead()->status !== Lead::OPEN && $this->may('pipelines.write'))
                    ->schema([$note()])
                    ->action(fn (array $data) => $this->run(fn () => $leads()->reopen($this->actor(), $this->lead(), $data['note'] ?? null))),
            ])->label(__('admin.users.actions'))->button()->color('gray'),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        $lead = $this->lead()->loadMissing(['person', 'pipeline', 'stage', 'responsible']);
        $l = fn (string $key): string => __('admin.leads.'.$key);
        $stages = PipelineStage::query()->where('pipeline_id', $lead->pipeline_id)->get()->keyBy('id');
        $people = fn (?int $id): ?string => $id !== null ? Person::query()->find($id)?->fullName() : null;
        $seesFeed = app(AuthorizationService::class)->can($this->actor(), 'crm.interactions.read', $lead->person);
        $kinds = Options::catalog('interaction_kinds');

        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('person')->label($l('person'))->state($lead->person->fullName())->weight('bold')
                    ->url(PersonResource::canView($lead->person) ? PersonResource::getUrl('view', ['record' => $lead->person]) : null),
                TextEntry::make('pipeline')->label($l('pipeline'))->state($lead->pipeline->name()),
                TextEntry::make('stage')->label($l('stage'))->state($lead->stage->name())->badge()->color('gray'),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge()
                    ->state(__('crm.lead_statuses.'.$lead->status))->color(LeadResource::statusColor($lead->status)),
                TextEntry::make('responsible')->label($l('responsible'))->state($lead->responsible?->fullName())->placeholder('—'),
                TextEntry::make('in_stage')->label($l('in_stage_since'))->state($lead->stage_entered_at?->isoFormat('LL'))->placeholder('—'),
                TextEntry::make('stage_due')->label($l('stage_due'))->state($lead->stage_due_at?->isoFormat('LLL'))->visible($lead->stage_due_at !== null)
                    ->color($lead->isStageOverdue() ? 'danger' : null)->helperText($lead->isStageOverdue() ? $l('stage_overdue') : null),
                TextEntry::make('frozen_until')->label($l('frozen_until'))->state($lead->frozen_until?->isoFormat('LL'))->visible($lead->status === Lead::FROZEN),
                TextEntry::make('loss_reason')->label($l('loss_reason'))->visible($lead->status === Lead::LOST)
                    ->state(Options::catalog('lead_loss_reasons')[(string) $lead->lost_reason_code] ?? $lead->lost_reason_code),
                TextEntry::make('unit')->label(__('admin.org_units.singular'))->state(Places::unitName($lead->org_unit_id))->placeholder('—'),
                TextEntry::make('territory')->label(__('admin.territories.singular'))->state(Places::territoryName($lead->territory_id))->placeholder('—'),
                TextEntry::make('source')->label(__('admin.people.source'))->placeholder('—')
                    ->state($lead->source_code !== null ? (Options::catalog('contact_sources')[$lead->source_code] ?? $lead->source_code) : null),
                TextEntry::make('status_note')->label($l('note'))->state($lead->status_note)->placeholder('—')->columnSpanFull(),
            ]),
            Section::make($l('history'))->schema([
                RepeatableEntry::make('history')->hiddenLabel()->columns(4)
                    ->state(LeadStageHistory::query()->where('lead_id', $lead->id)->latest('id')->get()->map(fn (LeadStageHistory $move): array => [
                        'at' => $move->created_at->isoFormat('LLL'),
                        'move' => ($move->from_stage_id !== null && $move->from_stage_id !== $move->to_stage_id ? ($stages->get($move->from_stage_id)?->name() ?? '—').' → ' : '')
                            .($stages->get($move->to_stage_id)?->name() ?? '—'),
                        'status' => __('crm.lead_statuses.'.$move->to_status),
                        'by' => $people($move->moved_by_person_id) ?? __('journal.actor_types.system'),
                        'note' => $move->note,
                    ])->all())
                    ->schema([
                        TextEntry::make('at')->hiddenLabel(),
                        TextEntry::make('move')->hiddenLabel()->weight('bold'),
                        TextEntry::make('status')->hiddenLabel()->badge()->color('gray'),
                        TextEntry::make('by')->hiddenLabel(),
                        TextEntry::make('note')->hiddenLabel()->placeholder('')->columnSpanFull(),
                    ]),
            ]),
            Section::make(__('admin.crm.interactions'))->visible($seesFeed)->collapsible()->schema([
                RepeatableEntry::make('interactions')->hiddenLabel()->placeholder('—')->columns(3)
                    ->state(Interaction::query()->with('author')->where('person_id', $lead->person_id)->latest('occurred_at')->limit(30)->get()
                        ->map(fn (Interaction $interaction): array => [
                            'at' => $interaction->occurred_at->isoFormat('LLL'),
                            'kind' => ($kinds[$interaction->kind_code] ?? $interaction->kind_code).($interaction->author !== null ? ' · '.$interaction->author->fullName() : ''),
                            'summary' => $interaction->summary,
                        ])->all())
                    ->schema([TextEntry::make('at')->hiddenLabel(), TextEntry::make('kind')->hiddenLabel()->weight('bold'), TextEntry::make('summary')->hiddenLabel()->placeholder('')]),
            ]),
        ]);
    }
}
