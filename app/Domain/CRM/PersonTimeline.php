<?php

declare(strict_types=1);

namespace App\Domain\CRM;

use App\Domain\Access\AuthorizationService;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\CRM\Models\Appeal;
use App\Domain\CRM\Models\Interaction;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\LeadStageHistory;
use App\Domain\CRM\Models\PipelineStage;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\People\Models\PersonStatusHistory;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The feed of a person (ФО §6.9.1): every touch in one list — interactions, lead moves, appeals, tasks about
 * the person, status changes. Each source is read under its own permission, so the feed never shows more than
 * the separate lists would.
 */
final readonly class PersonTimeline
{
    public const array SOURCES = ['interaction', 'lead', 'appeal', 'task', 'status'];

    public function __construct(private AuthorizationService $authorization) {}

    /**
     * @param  list<string>  $sources  empty = all
     * @return Collection<int, array{at: Carbon, source: string, title: string, details: string|null, url: string|null}>
     */
    public function for(User $viewer, Person $person, array $sources = [], int $limit = 100): Collection
    {
        if (! $this->authorization->can($viewer, 'people.read', $person)) {
            return collect();
        }
        $wants = fn (string $source): bool => $sources === [] || in_array($source, $sources, true);
        $entries = collect();

        if ($wants('interaction') && $this->authorization->can($viewer, 'crm.interactions.read', $person)) {
            $kinds = CatalogItem::query()->ofCatalog('interaction_kinds')->get()->keyBy('code');
            foreach (Interaction::query()->with('author')->where('person_id', $person->id)->latest('occurred_at')->limit($limit)->get() as $interaction) {
                $entries->push([
                    'at' => $interaction->occurred_at,
                    'source' => 'interaction',
                    'title' => ($kinds->get($interaction->kind_code)?->name() ?? $interaction->kind_code)
                        .($interaction->direction !== null ? ' · '.__('crm.directions.'.$interaction->direction) : ''),
                    'details' => trim(($interaction->summary ?? '').($interaction->author !== null ? ' — '.$interaction->author->fullName() : '')) ?: null,
                    'url' => null,
                ]);
            }
        }

        if ($wants('lead')) {
            $leads = $this->authorization->scopeQuery($viewer, 'pipelines.read', Lead::query())->with('pipeline')->where('person_id', $person->id)->get()->keyBy('id');
            $stages = PipelineStage::query()->whereIn('pipeline_id', $leads->pluck('pipeline_id'))->get()->keyBy('id');
            foreach (LeadStageHistory::query()->whereIn('lead_id', $leads->keys())->latest('id')->limit($limit)->get() as $move) {
                $lead = $leads[$move->lead_id];
                $stage = $stages->get($move->to_stage_id);
                $entries->push([
                    'at' => $move->created_at,
                    'source' => 'lead',
                    'title' => $lead->pipeline->name().': '.($move->to_status === Lead::OPEN || $move->to_status === Lead::WON
                        ? ($stage?->name() ?? '—')
                        : __('crm.lead_statuses.'.$move->to_status)),
                    'details' => $move->note,
                    'url' => '/admin/leads/'.$lead->id,
                ]);
            }
        }

        if ($wants('appeal')) {
            foreach ($this->authorization->scopeQuery($viewer, 'appeals.read', Appeal::query())->where('person_id', $person->id)->latest('id')->limit($limit)->get() as $appeal) {
                $entries->push([
                    'at' => $appeal->created_at,
                    'source' => 'appeal',
                    'title' => $appeal->number.' · '.$appeal->title,
                    'details' => __('crm.appeal_statuses.'.$appeal->status),
                    'url' => '/admin/appeals/'.$appeal->id,
                ]);
            }
        }

        if ($wants('task')) {
            foreach ($this->authorization->scopeQuery($viewer, 'tasks.read', Task::query())->where('subject_person_id', $person->id)->latest('id')->limit($limit)->get() as $task) {
                $entries->push([
                    'at' => $task->created_at,
                    'source' => 'task',
                    'title' => $task->title,
                    'details' => null,
                    'url' => '/admin/tasks/'.$task->id,
                ]);
            }
        }

        if ($wants('status') && $this->authorization->can($viewer, 'people.status_history.read', $person)) {
            $types = CatalogItem::query()->ofCatalog('person_types')->get()->keyBy('code');
            foreach (PersonStatusHistory::query()->where('person_id', $person->id)->latest('id')->limit($limit)->get() as $change) {
                $entries->push([
                    'at' => $change->created_at,
                    'source' => 'status',
                    'title' => $change->kind === PersonStatusHistory::TYPE
                        ? __('crm.status_history.type', [
                            'from' => $change->old_value !== null ? ($types->get($change->old_value)?->name() ?? $change->old_value) : '—',
                            'to' => $types->get((string) $change->new_value)?->name() ?? (string) $change->new_value,
                        ])
                        : __('crm.status_history.'.$change->kind),
                    'details' => $change->note,
                    'url' => null,
                ]);
            }
        }

        return $entries->sortByDesc(fn (array $entry): int => $entry['at']->getTimestamp())->take($limit)->values();
    }
}
