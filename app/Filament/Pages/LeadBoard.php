<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\Actions\ManageLeads;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Models\PipelineStage;
use App\Domain\Identity\Models\User;
use App\Filament\Support\Options;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The pipeline board (ФО §5.4, §6.9.2): stages are columns, moving a card changes the lead. Cards and the
 * counters of each column show only the leads the viewer may read.
 */
class LeadBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.pages.lead-board';

    public ?int $pipelineId = null;

    public bool $onlyMine = false;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AuthorizationService::class)->can($user, 'pipelines.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.crm');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.leads.board');
    }

    public function getTitle(): string
    {
        return __('admin.leads.board');
    }

    public function mount(): void
    {
        $this->pipelineId = request()->integer('pipeline') ?: (array_key_first($this->getPipelinesProperty()) ?? null);
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * @return array<int, string>
     */
    public function getPipelinesProperty(): array
    {
        return Pipeline::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (Pipeline $pipeline): array => [$pipeline->id => $pipeline->name()])->all();
    }

    /**
     * @return Builder<Lead>
     */
    public function visibleLeads(): Builder
    {
        return app(AuthorizationService::class)->scopeQuery($this->actor(), 'pipelines.read', Lead::query())
            ->where('pipeline_id', $this->pipelineId)
            ->when($this->onlyMine, fn (Builder $query) => $query->where('responsible_person_id', $this->actor()->person_id));
    }

    /**
     * @return list<array{stage: PipelineStage, count: int, leads: Collection<int, Lead>}>
     */
    public function getColumnsProperty(): array
    {
        if ($this->pipelineId === null) {
            return [];
        }
        // Counters come from the same scoped query as the cards — never the total of the pipeline.
        $counts = $this->visibleLeads()->selectRaw('stage_id, count(*) as total')->groupBy('stage_id')->pluck('total', 'stage_id');
        $columns = [];
        foreach (PipelineStage::query()->where('pipeline_id', $this->pipelineId)->where('is_active', true)->orderBy('sort_order')->get() as $stage) {
            $columns[] = [
                'stage' => $stage,
                'count' => (int) ($counts[$stage->id] ?? 0),
                'leads' => $this->visibleLeads()->with(['person', 'responsible'])->where('stage_id', $stage->id)->latest('stage_entered_at')->limit(50)->get(),
            ];
        }

        return $columns;
    }

    public function mayMove(Lead $lead): bool
    {
        return $lead->status === Lead::OPEN && app(AuthorizationService::class)->can($this->actor(), 'pipelines.write', $lead);
    }

    public function moveAction(): Action
    {
        return Action::make('move')
            ->modalHeading(fn (array $arguments): string => __('admin.leads.move').': '.(PipelineStage::query()->find($arguments['stage'] ?? 0)?->name() ?? ''))
            ->schema(function (array $arguments): array {
                $lost = PipelineStage::query()->find($arguments['stage'] ?? 0)?->kind === PipelineStage::LOST;

                return array_values(array_filter([
                    $lost ? Select::make('reason')->label(__('admin.leads.loss_reason'))->options(fn (): array => Options::catalog('lead_loss_reasons'))->required() : null,
                    Textarea::make('note')->label(__('admin.leads.note'))->rows(2),
                ]));
            })
            ->action(function (array $arguments, array $data): void {
                try {
                    $lead = Lead::query()->findOrFail($arguments['lead'] ?? 0);
                    $stage = PipelineStage::query()->findOrFail($arguments['stage'] ?? 0);
                    $stage->kind === PipelineStage::LOST
                        ? app(ManageLeads::class)->lose($this->actor(), $lead, (string) ($data['reason'] ?? ''), $data['note'] ?? null)
                        : app(ManageLeads::class)->move($this->actor(), $lead, $stage, $data['note'] ?? null);
                    Notification::make()->title(__('admin.saved'))->success()->send();
                } catch (AuthorizationException|DomainException $e) {
                    Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();
                }
            });
    }
}
