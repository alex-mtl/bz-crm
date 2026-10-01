<?php

namespace Database\Seeders\Demo;

use App\Domain\Identity\Actions\SetUserActive;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Actions\ManageProjects;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectPhase;
use App\Domain\Projects\Models\ProjectTemplate;
use App\Domain\Tasks\Actions\ManageTasks;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Projects and tasks of the demo world (plan 2e): a phased project from a template (Gantt), a flat one with a budget,
 * an organization-wide one; tasks in every status of ФО §6.8.4, overdue and blocked ones, several assignees,
 * three levels of subtasks, checklists, a recurring report, time entries, discussion; a candidate as a task's subject
 * (Д-15) and an open task of a deactivated user with a reassignment hint.
 */
class WorkDemoSeeder extends Seeder
{
    use DemoSteps;

    private ManageTasks $tasks;

    public function run(): void
    {
        $this->tasks = app(ManageTasks::class);

        try {
            $template = $this->at(25, fn () => $this->template());
            $campaign = $this->at(20, fn () => $this->campaign($template));
            $this->at(18, fn () => $this->campaignTasks($campaign));
            $this->at(15, fn () => $this->renovation());
            $this->at(12, fn () => $this->training());
            $this->at(35, fn () => $this->igorsTask());
            $this->at(30, fn () => $this->deactivateIgor());
            $this->at(5, fn () => $this->balti());
            $this->at(0, fn () => Artisan::call('tasks:escalate'));
        } finally {
            $this->resetClock();
        }
    }

    /**
     * Deadlines are relative to the real "now", not to the moment of the step: the world is seeded in the past.
     */
    private function dueIn(int $days, int $hour = 18): Carbon
    {
        return ($this->demoNow ?? now())->copy()->addDays($days)->setTime($hour, 0);
    }

    private function as2(string $key, callable $step): mixed
    {
        return $this->as(Personas::user($key), fn () => $step(Personas::user($key)));
    }

    private function pid(string $key): int
    {
        return Personas::user($key)->person_id;
    }

    /**
     * @param  list<string>  $assignees
     * @param  array<string, mixed>  $extra
     * @param  list<string>  $watchers
     */
    private function task(string $creator, string $title, string $type, array $assignees, array $extra = [], array $watchers = []): Task
    {
        return $this->as2($creator, fn (User $u) => $this->tasks->create($u, ['title' => $title, 'type_code' => $type, ...$extra],
            array_map(fn (string $k): int => $this->pid($k), $assignees), array_map(fn (string $k): int => $this->pid($k), $watchers)));
    }

    private function move(string $who, Task $task, string $to, ?string $note = null, ?string $blockedBy = null): void
    {
        $this->as2($who, fn (User $u) => $this->tasks->changeStatus($u, $task->fresh() ?? $task, $to, $note, $blockedBy));
    }

    private function template(): ProjectTemplate
    {
        return $this->as2('org_head', fn (User $u) => app(ManageProjects::class)->saveTemplate($u, 'Agitație stradală', 'Ieșire în teren a unei filiale: pregătire, ieșire, raport.', [
            'structure' => 'phases',
            'strict_phases' => true,
            'phases' => [
                ['name' => 'Pregătire', 'offset_days' => 0, 'duration_days' => 6, 'tasks' => [
                    ['title' => 'Materiale tipărite', 'type_code' => 'logistics', 'offset_days' => 4],
                ]],
                ['name' => 'Ieșire în teren', 'offset_days' => 6, 'duration_days' => 10, 'tasks' => [
                    ['title' => 'Ieșire în teren: str. Ștefan cel Mare', 'type_code' => 'field_visit', 'offset_days' => 12],
                ]],
                ['name' => 'Raport', 'offset_days' => 16, 'duration_days' => 7, 'tasks' => [
                    ['title' => 'Raportul campaniei', 'type_code' => 'report', 'offset_days' => 22],
                ]],
            ],
        ]));
    }

    private function campaign(ProjectTemplate $template): Project
    {
        return $this->as2('branch_a_head', fn (User $u) => app(ManageProjects::class)->create($u, [
            'name' => 'Campania de toamnă — Centru',
            'description' => 'Informarea locuitorilor sectorului Centru.',
            'starts_on' => $this->dueIn(-8)->toDateString(),
            'due_on' => $this->dueIn(15)->toDateString(),
            'budget_plan' => 5000,
        ], array_map(fn (string $k): int => $this->pid($k), ['branch_a_employee_1', 'branch_a_employee_2', 'branch_a_employee_3', 'volunteer', 'google_user']), $template));
    }

    private function campaignTasks(Project $project): void
    {
        $phases = ProjectPhase::query()->where('project_id', $project->id)->orderBy('sort_order')->get()->values();
        $manage = app(ManageProjects::class);
        $ana = Personas::user('branch_a_head');
        $this->as($ana, fn () => $manage->setPhaseStatus($ana, $phases[0], 'in_progress'));

        // The template tasks: materials done; the field visit depends on them.
        $materials = Task::query()->where('project_id', $project->id)->where('phase_id', $phases[0]->id)->firstOrFail();
        $visit = Task::query()->where('project_id', $project->id)->where('phase_id', $phases[1]->id)->firstOrFail();
        $this->as($ana, fn () => $this->tasks->assign($ana, $materials, [$this->pid('branch_a_employee_3')]));
        $this->move('branch_a_head', $materials, 'todo');
        $this->move('branch_a_employee_3', $materials, 'in_progress');
        $this->as2('branch_a_employee_3', fn (User $u) => $this->tasks->logTime($u, $materials, 150, now()->subDay(), 'Comanda la tipografie'));
        $this->move('branch_a_employee_3', $materials, 'done');
        $this->as($ana, fn () => $this->tasks->addDependency($ana, $visit, $materials));
        $this->as($ana, fn () => $manage->setPhaseStatus($ana, $phases[0], 'completed'));
        $this->as($ana, fn () => $manage->setPhaseStatus($ana, $phases[1], 'in_progress'));
        $this->as($ana, fn () => $this->tasks->assign($ana, $visit, array_map(fn ($k) => $this->pid($k), ['branch_a_employee_1', 'volunteer'])));
        $this->move('branch_a_head', $visit, 'todo');
        $this->move('branch_a_employee_1', $visit, 'in_progress');
        // The report stays a draft: no assignee yet.

        $in = ['project_id' => $project->id, 'phase_id' => $phases[1]->id];

        // Several assignees, a watcher, a checklist with responsibles, a discussion.
        $flyers = $this->task('branch_a_head', 'Distribuirea pliantelor în blocurile de pe bd. Negruzzi', 'field_visit',
            ['branch_a_employee_1', 'branch_a_employee_3', 'volunteer'], [...$in, 'due_at' => $this->dueIn(6, 18), 'priority_code' => 'high'], ['branch_a_employee_2']);
        foreach ([['Blocurile 1–10', 'branch_a_employee_1'], ['Blocurile 11–20', 'branch_a_employee_3'], ['Cutiile poștale din zona pieței', 'volunteer']] as [$item, $who]) {
            $this->as($ana, fn () => $this->tasks->addChecklistItem($ana, $flyers, $item, $this->pid($who)));
        }
        $first = $flyers->checklist()->first();
        $this->as2('branch_a_employee_1', fn (User $u) => $this->tasks->toggleChecklistItem($u, $first, true));
        $this->move('branch_a_employee_1', $flyers, 'in_progress');
        foreach ([['branch_a_employee_1', 'Am început cu blocul 1.'], ['branch_a_employee_3', 'Mâine iau mașina pentru restul.'], ['branch_a_head', 'Mulțumesc! Raportați la final numărul de pliante.']] as [$who, $text]) {
            $this->as2($who, fn (User $u) => $this->tasks->post($u, $flyers, $text));
        }

        // Three levels of subtasks.
        $event = $this->task('branch_a_head', 'Întâlnirea cu locuitorii sectorului', 'event_preparation', ['branch_a_employee_2'], [...$in, 'due_at' => $this->dueIn(10, 18)]);
        $logistics = $this->task('branch_a_head', 'Logistica întâlnirii', 'logistics', ['branch_a_employee_2'], [...$in, 'parent_id' => $event->id]);
        $this->task('branch_a_head', 'Închirierea sălii', 'logistics', ['branch_a_employee_3'], [...$in, 'parent_id' => $logistics->id, 'due_at' => $this->dueIn(5, 12)]);
        $this->task('branch_a_head', 'Sonorizarea', 'logistics', ['branch_a_employee_3'], [...$in, 'parent_id' => $logistics->id]);

        // Every status of ФО §6.8.4.
        $backlog = $this->task('branch_a_head', 'Idee: concurs de desene pentru copii', 'other', []);
        $this->move('branch_a_head', $backlog, 'backlog');
        $this->task('branch_a_head', 'Actualizarea listei de voluntari', 'data_update', ['branch_a_employee_2'], ['due_at' => $this->dueIn(3, 18)]);
        $blocked = $this->task('branch_a_head', 'Autorizația pentru cortul de informare', 'request', ['branch_a_employee_1'], [...$in, 'due_at' => $this->dueIn(2, 12)]);
        $this->move('branch_a_employee_1', $blocked, 'in_progress');
        $this->move('branch_a_employee_1', $blocked, 'blocked', 'Așteptăm răspunsul primăriei.', 'Primăria sectorului Centru');
        $hold = $this->task('branch_a_head', 'Tipărirea calendarelor pentru 2027', 'logistics', ['branch_a_employee_3']);
        $this->move('branch_a_head', $hold, 'on_hold', now()->addDays(20)->toDateString());
        $review = $this->task('branch_a_head', 'Textul pentru pagina filialei', 'content_preparation', ['branch_a_employee_2'], ['due_at' => $this->dueIn(1, 18)]);
        $this->move('branch_a_employee_2', $review, 'in_progress');
        $this->move('branch_a_employee_2', $review, 'in_review');
        $canceled = $this->task('branch_a_head', 'Concert în parcul central', 'event_preparation', ['branch_a_employee_3']);
        $this->move('branch_a_head', $canceled, 'canceled', 'Primăria a refuzat autorizația pentru eveniment.');

        // Overdue (a computed flag, not a status).
        $late = $this->task('branch_a_head', 'Actualizarea listei de contacte a alegătorilor', 'data_update', ['branch_a_employee_3'], ['due_at' => $this->dueIn(-3, 18)]);
        $this->move('branch_a_employee_3', $late, 'in_progress');

        // A recurring weekly report.
        $this->task('branch_a_head', 'Raportul săptămânal al filialei', 'report', ['branch_a_employee_2'],
            ['due_at' => ($this->demoNow ?? now())->copy()->next(Carbon::FRIDAY)->setTime(17, 0), 'recurrence' => ['freq' => 'weekly', 'interval' => 1]]);

        // Д-15: the candidate is the subject; an employee is the assignee.
        $candidate = Person::query()->where('first_name', 'Lilia')->where('last_name', 'Zaharia')->firstOrFail();
        $this->task('branch_a_head', 'Sunați candidata Lilia Zaharia', 'call', ['branch_a_employee_1'],
            ['subject_person_id' => $candidate->id, 'due_at' => $this->dueIn(2, 15)]);

        // An employee's own task (the employee assigns only themselves).
        $this->task('branch_a_employee_1', 'Să învăț lista de întrebări frecvente', 'other', ['branch_a_employee_1']);
    }

    private function renovation(): void
    {
        $project = $this->as2('branch_b_head', fn (User $u) => app(ManageProjects::class)->create($u, [
            'name' => 'Reparația sediului Botanica',
            'description' => 'Reparație cosmetică a sediului filialei B.',
            'starts_on' => now()->toDateString(),
            'due_on' => now()->addDays(40)->toDateString(),
        ], array_map(fn ($k) => $this->pid($k), ['branch_b_employee_1', 'branch_b_employee_2', 'branch_b_employee_3', 'branch_b_acting_head'])));
        $pavel = Personas::user('branch_b_head');
        $this->as($pavel, fn () => app(ManageProjects::class)->setBudget($pavel, $project, 20000, 8500));
        $this->as($pavel, fn () => app(ManageProjects::class)->addResource($pavel, $project, ['kind_code' => 'money', 'description' => 'Vopsea și materiale', 'plan_amount' => 12000, 'fact_amount' => 8500]));
        $this->as($pavel, fn () => app(ManageProjects::class)->addResource($pavel, $project, ['kind_code' => 'people', 'description' => 'Voluntari la zugrăvit', 'plan_amount' => 6, 'fact_amount' => 3]));
        $this->as($pavel, fn () => app(ManageProjects::class)->setStatus($pavel, $project, 'in_progress'));

        $paint = $this->task('branch_b_head', 'Zugrăvirea sălii mari', 'logistics', ['branch_b_employee_1', 'branch_b_employee_2'], ['project_id' => $project->id, 'due_at' => $this->dueIn(9, 18)]);
        $this->move('branch_b_employee_1', $paint, 'in_progress');
        $this->as2('branch_b_employee_1', fn (User $u) => $this->tasks->logTime($u, $paint, 240, now(), 'Pregătirea pereților'));
        $this->task('branch_b_head', 'Comanda de mobilier', 'logistics', ['branch_b_employee_3'], ['project_id' => $project->id, 'due_at' => $this->dueIn(20, 12)]);
    }

    private function training(): void
    {
        $project = $this->as2('org_head', fn (User $u) => app(ManageProjects::class)->create($u, [
            'name' => 'Instruirea voluntarilor',
            'description' => 'Program comun pentru toate organizațiile regionale.',
            'visibility' => 'organization',
            'starts_on' => now()->subDays(10)->toDateString(),
            'due_on' => now()->addDays(30)->toDateString(),
        ], [$this->pid('hr')]));
        $elena = Personas::user('org_head');
        $this->as($elena, fn () => app(ManageProjects::class)->setStatus($elena, $project, 'in_progress', 'at_risk'));
        $this->task('org_head', 'Curriculumul instruirii', 'document_preparation', ['hr'], ['project_id' => $project->id, 'due_at' => $this->dueIn(5, 18)]);
    }

    private function igorsTask(): void
    {
        $this->task('branch_a_head', 'Verificarea listelor de semnături', 'check', ['deactivated'], ['due_at' => $this->dueIn(40, 18)]);
    }

    /**
     * Igor is deactivated (ФО §6.1); his open task stays with its history, and Ana gets a "reassign" hint (Д-15).
     */
    private function deactivateIgor(): void
    {
        $this->as2('hr', fn (User $u) => app(SetUserActive::class)($u, Personas::user('deactivated'), false));
    }

    private function balti(): void
    {
        $this->task('balti_head', 'Întâlnire cu alegătorii din Bălți', 'meeting', ['balti_employee_1', 'balti_employee_2'], ['due_at' => $this->dueIn(4, 17)]);
    }
}
