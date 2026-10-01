<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Tasks\Models\StatusTransition;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Status rules of ФО §6.8.4. Transitions are data (the super admin may add statuses and transitions);
 * on top of them three rules are fixed in code because other logic relies on them:
 *  - into `done` straight from `in_progress` only if the task type does not require review (Д-16);
 *  - leaving `blocked` / `on_hold` returns to the status the task had before;
 *  - a task starts only when the tasks it depends on are finished ("start after", ФО §6.8.1).
 */
final class TaskWorkflow
{
    /** from, to, requires */
    public const array DEFAULT_TRANSITIONS = [
        ['draft', 'backlog', 'none'], ['draft', 'todo', 'none'], ['draft', 'canceled', 'reason'],
        ['backlog', 'todo', 'none'], ['backlog', 'canceled', 'reason'],
        ['todo', 'in_progress', 'none'], ['todo', 'blocked', 'reason'], ['todo', 'on_hold', 'date'], ['todo', 'canceled', 'reason'],
        ['in_progress', 'in_review', 'none'], ['in_progress', 'done', 'none'], ['in_progress', 'blocked', 'reason'],
        ['in_progress', 'on_hold', 'date'], ['in_progress', 'canceled', 'reason'],
        ['in_review', 'done', 'none'], ['in_review', 'in_progress', 'comment'],
        ['done', 'in_progress', 'comment'],
        ['blocked', 'todo', 'none'], ['blocked', 'in_progress', 'none'],
        ['on_hold', 'todo', 'none'], ['on_hold', 'in_progress', 'none'], ['on_hold', 'canceled', 'reason'],
    ];

    /** returning from review or from done is a "reopen" (tasks.reopen) */
    public const array REOPEN_FROM = ['done', 'in_review'];

    /**
     * Reference data: creates the default transitions that do not exist yet (admin edits are kept).
     */
    public static function ensureDefaults(): int
    {
        $created = 0;
        foreach (self::DEFAULT_TRANSITIONS as [$from, $to, $requires]) {
            $row = StatusTransition::query()->firstOrCreate(['from_status' => $from, 'to_status' => $to], ['requires' => $requires]);
            $created += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    public function transition(string $from, string $to): ?StatusTransition
    {
        return StatusTransition::query()->where('from_status', $from)->where('to_status', $to)->where('is_active', true)->first();
    }

    /**
     * @return list<string> statuses reachable from the task's current status, all rules applied
     */
    public function nextStatuses(Task $task): array
    {
        return StatusTransition::query()->where('from_status', $task->status_code)->where('is_active', true)->pluck('to_status')
            ->filter(fn (string $to): bool => $this->violation($task, $to) === null)->values()->all();
    }

    /**
     * @return string|null key of the broken rule (translated under tasks.errors.*), null when allowed
     */
    public function violation(Task $task, string $to): ?string
    {
        if ($this->transition($task->status_code, $to) === null) {
            return 'transition_not_allowed';
        }
        if ($to === 'done' && $task->status_code === 'in_progress' && $this->requiresReview($task->type_code)) {
            return 'review_required';
        }
        if (in_array($task->status_code, ['blocked', 'on_hold'], true) && $to !== 'canceled'
            && $task->status_before !== null && $to !== $task->status_before) {
            return 'return_to_previous';
        }
        if ($to === 'in_progress' && $task->status_code !== 'in_review' && $task->status_code !== 'done'
            && $task->dependsOn()->whereNotIn('status_code', Task::CLOSED)->exists()) {
            return 'dependencies_open';
        }
        if ($to === 'in_progress' && $task->phase_id !== null && $this->earlierPhaseOpen($task->phase_id)) {
            return 'previous_phase_open';
        }

        return null;
    }

    /**
     * ФО §6.8.1: with strict phases, the next phase does not start until the previous one is finished.
     */
    private function earlierPhaseOpen(int $phaseId): bool
    {
        $phase = DB::table('project_phases')->join('projects', 'projects.id', '=', 'project_phases.project_id')
            ->where('project_phases.id', $phaseId)->first(['project_phases.project_id', 'project_phases.sort_order', 'projects.strict_phases']);
        if ($phase === null || ! (bool) $phase->strict_phases) {
            return false;
        }

        return DB::table('project_phases')->where('project_id', $phase->project_id)->where('sort_order', '<', $phase->sort_order)
            ->whereNotIn('status', ['completed', 'canceled'])->exists();
    }

    public function requires(string $from, string $to): string
    {
        return $this->transition($from, $to)->requires ?? 'none';
    }

    public function requiresReview(string $typeCode): bool
    {
        $type = CatalogItem::query()->ofCatalog('task_types')->where('code', $typeCode)->first();

        return (bool) $type?->property('requires_review', false);
    }

    public function category(string $statusCode): string
    {
        $status = CatalogItem::query()->ofCatalog('task_statuses')->where('code', $statusCode)->first();

        return (string) ($status?->property('category', 'none') ?? 'none');
    }
}
