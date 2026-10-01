<?php

declare(strict_types=1);

namespace App\Domain\CRM\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Events\LeadStageChanged;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\LeadStageHistory;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Models\PipelineStage;
use App\Domain\CRM\Notifications\CrmNotice;
use App\Domain\CRM\ResponsibleByTerritory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Domain\People\Models\Person;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leads (ФО §6.9.2): a person's way through a pipeline. Every move — between stages, lost with a reason,
 * frozen until a date, returned — is a history row, a journal entry and a domain event.
 */
final readonly class ManageLeads
{
    public function __construct(
        private AuthorizationService $authorization,
        private OrgStructure $org,
        private ResponsibleByTerritory $byTerritory,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{title?: string|null, stage_id?: int|null, responsible_person_id?: int|null, org_unit_id?: int|null,
     *               territory_id?: int|null, source_code?: string|null}  $data
     * @param  array<string, mixed>  $extra  set by the system (e.g. import_batch_id)
     */
    public function create(User $actor, Pipeline $pipeline, Person $person, array $data = [], array $extra = []): Lead
    {
        if (! $pipeline->is_active) {
            throw CrmRuleViolation::because('pipeline_inactive');
        }
        $stage = isset($data['stage_id']) ? $this->stageOf($pipeline, (int) $data['stage_id']) : $pipeline->firstStage();
        if ($stage === null || $stage->kind !== PipelineStage::OPEN) {
            throw CrmRuleViolation::because('lead_starts_in_open_stage');
        }

        $lead = new Lead([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'person_id' => $person->id,
            'title' => filled($data['title'] ?? null) ? trim((string) $data['title']) : null,
            'org_unit_id' => $data['org_unit_id'] ?? $person->responsible_unit_id ?? $this->org->unitOf($person->id)->id ?? $this->org->unitOf($actor->person_id)?->id,
            'territory_id' => $data['territory_id'] ?? $person->territory_id,
            'source_code' => $data['source_code'] ?? $person->source_code,
            'status' => Lead::OPEN,
            'created_by_person_id' => $actor->person_id,
            ...$extra,
        ]);
        $this->authorization->authorize($actor, 'leads.create', $lead);
        $this->authorization->authorize($actor, 'people.read', $person);
        if ($person->isArchived()) {
            throw CrmRuleViolation::because('person_archived');
        }
        if (Lead::query()->where('pipeline_id', $pipeline->id)->where('person_id', $person->id)->whereIn('status', [Lead::OPEN, Lead::FROZEN])->exists()) {
            throw CrmRuleViolation::because('lead_already_open');
        }

        $responsibleId = $data['responsible_person_id'] ?? null;
        if ($responsibleId !== null) {
            if ($responsibleId !== $actor->person_id) {
                $this->authorization->authorize($actor, 'leads.assign', $lead);
            }
            $this->ensureActiveUser($responsibleId);
            $lead->responsible_person_id = $responsibleId;
        } elseif ($pipeline->auto_assign_by_territory) {
            $lead->responsible_person_id = $this->byTerritory->find($lead->territory_id);
        }

        return DB::transaction(fn (): Lead => $this->open($lead, $stage, $actor));
    }

    /**
     * A new card of a type the pipeline takes in by itself (Д-22) — e.g. a candidate enters "Joining the
     * organization" at once, and the responsible of the territory gets the lead. This is a rule of the pipeline,
     * not an action of a user: no permission of the one who created the card is involved.
     *
     * @return list<Lead>
     */
    public function enrollAutomatically(Person $person): array
    {
        $leads = [];
        if ($person->isArchived()) {
            return $leads;
        }

        foreach (Pipeline::query()->where('is_active', true)->whereNotNull('auto_enroll_types')->orderBy('sort_order')->get() as $pipeline) {
            $stage = $pipeline->firstStage();
            if ($stage === null || ! in_array($person->person_type, $pipeline->auto_enroll_types ?? [], true)
                || Lead::query()->where('pipeline_id', $pipeline->id)->where('person_id', $person->id)->exists()) {
                continue;
            }
            $lead = new Lead([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'person_id' => $person->id,
                'org_unit_id' => $person->responsible_unit_id ?? $this->org->unitOf($person->id)?->id,
                'territory_id' => $person->territory_id,
                'source_code' => $person->source_code,
                'status' => Lead::OPEN,
            ]);
            if ($pipeline->auto_assign_by_territory) {
                $lead->responsible_person_id = $this->byTerritory->find($lead->territory_id);
            }
            $leads[] = DB::transaction(fn (): Lead => $this->open($lead, $stage, null));
        }

        return $leads;
    }

    private function open(Lead $lead, PipelineStage $stage, ?User $actor): Lead
    {
        $lead->stage_entered_at = now();
        $lead->stage_due_at = $this->stageDeadline($stage);
        $lead->save();
        $this->history($lead, null, $stage->id, null, Lead::OPEN, $actor?->person_id, null);
        $this->journal->record('crm.lead.created', $lead, [], [
            ...$lead->only(['pipeline_id', 'stage_id', 'person_id', 'responsible_person_id', 'org_unit_id', 'territory_id']),
            ...($actor === null ? ['automatic' => true] : []),
        ]);
        LeadStageChanged::dispatch($lead, null, $stage->id, null, Lead::OPEN, $actor?->id);
        if ($lead->responsible_person_id !== null && $lead->responsible_person_id !== $actor?->person_id) {
            $lead->responsible?->user?->notify(new CrmNotice(CrmNotice::LEAD_ASSIGNED, $this->label($lead), '/admin/leads/'.$lead->id));
        }

        return $lead;
    }

    private function stageDeadline(PipelineStage $stage): ?Carbon
    {
        return $stage->kind === PipelineStage::OPEN && $stage->sla_hours !== null ? now()->addHours($stage->sla_hours) : null;
    }

    public function move(User $actor, Lead $lead, PipelineStage $stage, ?string $note = null): Lead
    {
        $this->authorization->authorize($actor, 'pipelines.write', $lead);
        if ($lead->status !== Lead::OPEN) {
            throw CrmRuleViolation::because('lead_not_open');
        }
        if ($stage->pipeline_id !== $lead->pipeline_id || ! $stage->is_active) {
            throw CrmRuleViolation::because('stage_of_other_pipeline');
        }
        if ($stage->kind === PipelineStage::LOST) {
            throw CrmRuleViolation::because('lost_needs_reason');
        }
        if ($stage->id === $lead->stage_id) {
            return $lead;
        }

        $won = $stage->kind === PipelineStage::WON;

        return $this->transition($actor, $lead, $stage->id, $won ? Lead::WON : Lead::OPEN, $note, ['closed_at' => $won ? now() : null]);
    }

    public function lose(User $actor, Lead $lead, string $reasonCode, ?string $note = null): Lead
    {
        $this->authorization->authorize($actor, 'leads.close', $lead);
        if (! in_array($lead->status, [Lead::OPEN, Lead::FROZEN], true)) {
            throw CrmRuleViolation::because('lead_already_closed');
        }
        if (! CatalogItem::query()->ofCatalog('lead_loss_reasons')->selectable()->where('code', $reasonCode)->exists()) {
            throw CrmRuleViolation::because('invalid_loss_reason');
        }
        // If the pipeline has a "lost" stage the lead goes there; otherwise it stays where it stopped.
        $lostStage = $lead->pipeline->stages()->where('is_active', true)->where('kind', PipelineStage::LOST)->first();

        return $this->transition($actor, $lead, $lostStage !== null ? $lostStage->id : $lead->stage_id, Lead::LOST, $note, [
            'lost_reason_code' => $reasonCode, 'closed_at' => now(), 'frozen_until' => null,
        ]);
    }

    public function freeze(User $actor, Lead $lead, Carbon $until, ?string $note = null): Lead
    {
        $this->authorization->authorize($actor, 'leads.close', $lead);
        if ($lead->status !== Lead::OPEN) {
            throw CrmRuleViolation::because('lead_not_open');
        }
        if (! $until->copy()->startOfDay()->isAfter(today())) {
            throw CrmRuleViolation::because('freeze_date_in_past');
        }

        return $this->transition($actor, $lead, $lead->stage_id, Lead::FROZEN, $note, ['frozen_until' => $until->toDateString()]);
    }

    /**
     * Returns a frozen or closed lead to work.
     */
    public function reopen(User $actor, Lead $lead, ?string $note = null): Lead
    {
        $this->authorization->authorize($actor, 'pipelines.write', $lead);
        if ($lead->status === Lead::OPEN) {
            return $lead;
        }
        if (Lead::query()->where('pipeline_id', $lead->pipeline_id)->where('person_id', $lead->person_id)
            ->whereKeyNot($lead->id)->whereIn('status', [Lead::OPEN, Lead::FROZEN])->exists()) {
            throw CrmRuleViolation::because('lead_already_open');
        }

        return $this->transition($actor, $lead, $this->lastOpenStageId($lead), Lead::OPEN, $note, [
            'lost_reason_code' => null, 'closed_at' => null, 'frozen_until' => null,
        ]);
    }

    public function assign(User $actor, Lead $lead, ?int $responsiblePersonId): Lead
    {
        $this->authorization->authorize($actor, 'leads.assign', $lead);
        if ($responsiblePersonId !== null) {
            $this->ensureActiveUser($responsiblePersonId);
        }
        if ($lead->responsible_person_id === $responsiblePersonId) {
            return $lead;
        }

        return DB::transaction(function () use ($actor, $lead, $responsiblePersonId): Lead {
            $old = $lead->responsible_person_id;
            $lead->update(['responsible_person_id' => $responsiblePersonId]);
            $lead->unsetRelation('responsible');
            $this->journal->record('crm.lead.assigned', $lead, ['responsible_person_id' => $old], ['responsible_person_id' => $responsiblePersonId]);
            $this->notifyResponsible($lead, $actor, CrmNotice::LEAD_ASSIGNED);

            return $lead;
        });
    }

    /**
     * Frozen leads whose date has come return to work by themselves (scheduler). Returns how many.
     */
    public function unfreezeDue(): int
    {
        $due = Lead::query()->where('status', Lead::FROZEN)->whereNotNull('frozen_until')->where('frozen_until', '<=', today())->get();
        foreach ($due as $lead) {
            DB::transaction(function () use ($lead): void {
                $this->apply($lead, $lead->stage_id, Lead::OPEN, null, __('crm.leads.unfrozen_automatically'), ['frozen_until' => null], null);
                $this->journal->record('crm.lead.unfrozen', $lead, [], ['stage_id' => $lead->stage_id]);
            });
            $lead->responsible?->user?->notify(new CrmNotice(CrmNotice::LEAD_UNFROZEN, $this->label($lead), '/admin/leads/'.$lead->id));
        }

        return $due->count();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(User $actor, Lead $lead, int $toStageId, string $toStatus, ?string $note, array $attributes): Lead
    {
        return DB::transaction(function () use ($actor, $lead, $toStageId, $toStatus, $note, $attributes): Lead {
            [$fromStage, $fromStatus] = [$lead->stage_id, $lead->status];
            $this->apply($lead, $toStageId, $toStatus, $actor->person_id, $note, $attributes, $actor->id);
            $this->journal->record('crm.lead.stage_changed', $lead,
                ['stage_id' => $fromStage, 'status' => $fromStatus],
                array_filter(['stage_id' => $toStageId, 'status' => $toStatus, 'lost_reason_code' => $lead->lost_reason_code, 'frozen_until' => $lead->frozen_until?->toDateString()]),
            );

            return $lead;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(Lead $lead, int $toStageId, string $toStatus, ?int $byPersonId, ?string $note, array $attributes, ?int $actorUserId): void
    {
        [$fromStage, $fromStatus] = [$lead->stage_id, $lead->status];
        $note = filled($note) ? trim((string) $note) : null;
        $lead->fill([
            ...$attributes,
            'stage_id' => $toStageId,
            'status' => $toStatus,
            'status_note' => $note,
            'stage_entered_at' => $toStageId !== $fromStage ? now() : $lead->stage_entered_at,
            // The clock of the stage starts when the lead enters it; returning to work (after a freeze) restarts it.
            'stage_due_at' => $toStatus !== Lead::OPEN ? null
                : ($toStageId !== $fromStage || $fromStatus !== Lead::OPEN
                    ? $this->stageDeadline(PipelineStage::query()->findOrFail($toStageId))
                    : $lead->stage_due_at),
        ])->save();
        $this->history($lead, $fromStage, $toStageId, $fromStatus, $toStatus, $byPersonId, $note);
        LeadStageChanged::dispatch($lead, $fromStage, $toStageId, $fromStatus, $toStatus, $actorUserId);
    }

    private function history(Lead $lead, ?int $fromStage, int $toStage, ?string $fromStatus, string $toStatus, ?int $byPersonId, ?string $note): void
    {
        LeadStageHistory::query()->create([
            'lead_id' => $lead->id, 'from_stage_id' => $fromStage, 'to_stage_id' => $toStage,
            'from_status' => $fromStatus, 'to_status' => $toStatus, 'moved_by_person_id' => $byPersonId, 'note' => $note,
        ]);
    }

    private function lastOpenStageId(Lead $lead): int
    {
        $open = $lead->pipeline->stages()->where('is_active', true)->where('kind', PipelineStage::OPEN)->pluck('id')->all();
        if (in_array($lead->stage_id, $open, true)) {
            return $lead->stage_id;
        }
        $previous = LeadStageHistory::query()->where('lead_id', $lead->id)->whereIn('to_stage_id', $open)->latest('id')->value('to_stage_id');
        if ($previous !== null) {
            return (int) $previous;
        }
        if ($open === []) {
            throw CrmRuleViolation::because('pipeline_needs_open_stage');
        }

        return (int) $open[0];
    }

    private function stageOf(Pipeline $pipeline, int $stageId): ?PipelineStage
    {
        return $pipeline->stages()->where('is_active', true)->whereKey($stageId)->first();
    }

    /**
     * Д-15: only a person with an active, approved account can be responsible.
     */
    private function ensureActiveUser(int $personId): void
    {
        $user = User::query()->where('person_id', $personId)->first();
        if ($user === null || ! $user->isActive()) {
            throw CrmRuleViolation::because('responsible_not_active_user');
        }
    }

    private function notifyResponsible(Lead $lead, User $actor, string $kind): void
    {
        if ($lead->responsible_person_id !== null && $lead->responsible_person_id !== $actor->person_id) {
            $lead->responsible?->user?->notify(new CrmNotice($kind, $this->label($lead), '/admin/leads/'.$lead->id));
        }
    }

    private function label(Lead $lead): string
    {
        return $lead->title ?? $lead->person->fullName();
    }
}
