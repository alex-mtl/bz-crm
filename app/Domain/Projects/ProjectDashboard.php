<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\People\Models\Person;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use Illuminate\Support\Facades\DB;

/**
 * Project dashboard (ФО §6.8.1): progress, overdue tasks, assignee workload, budget plan vs fact.
 * Reads only the project's own tasks; the caller has already checked projects.read.
 */
final class ProjectDashboard
{
    /**
     * @return array{total: int, done: int, progress: int, overdue: int, by_status: array<string, int>,
     *               workload: list<array{name: string, open: int, minutes: int}>,
     *               budget: array{plan: float, fact: float, resources: list<array{kind: string, plan: float, fact: float}>}}
     */
    public function summary(Project $project): array
    {
        $tasks = Task::query()->notDeleted()->where('project_id', $project->id);
        $byStatus = (clone $tasks)->groupBy('status_code')->selectRaw('status_code, count(*) as n')->pluck('n', 'status_code')
            ->map(fn ($n): int => (int) $n)->all();
        $total = array_sum($byStatus);
        $done = $byStatus['done'] ?? 0;

        $open = TaskPerson::query()->where('role', TaskPerson::ASSIGNEE)
            ->whereIn('task_id', (clone $tasks)->whereNotIn('status_code', Task::CLOSED)->select('id'))
            ->groupBy('person_id')->selectRaw('person_id, count(*) as n')->pluck('n', 'person_id');
        $minutes = DB::table('task_time_entries')->whereIn('task_id', (clone $tasks)->select('id'))
            ->groupBy('person_id')->selectRaw('person_id, sum(minutes) as m')->pluck('m', 'person_id');
        $names = Person::query()->whereKey($open->keys()->merge($minutes->keys())->unique())->get()->mapWithKeys(fn (Person $p): array => [$p->id => $p->fullName()]);

        $workload = $names->map(fn (string $name, int $id): array => [
            'name' => $name, 'open' => (int) ($open[$id] ?? 0), 'minutes' => (int) ($minutes[$id] ?? 0),
        ])->sortByDesc('open')->values()->all();

        $resources = DB::table('project_resources')->where('project_id', $project->id)->groupBy('kind_code')
            ->selectRaw('kind_code, coalesce(sum(plan_amount), 0) as plan, coalesce(sum(fact_amount), 0) as fact')->get()
            ->map(fn ($row): array => ['kind' => (string) $row->kind_code, 'plan' => (float) $row->plan, 'fact' => (float) $row->fact])->all();

        return [
            'total' => $total,
            'done' => $done,
            'progress' => $total > 0 ? (int) round($done * 100 / $total) : 0,
            'overdue' => (clone $tasks)->overdue()->count(),
            'by_status' => $byStatus,
            'workload' => $workload,
            'budget' => ['plan' => (float) $project->budget_plan, 'fact' => (float) $project->budget_fact, 'resources' => $resources],
        ];
    }
}
