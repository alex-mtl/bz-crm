<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Console\EscalateTasksCommand;
use App\Domain\Tasks\Exceptions\TaskRuleViolation;
use App\Domain\Tasks\Models\StatusTransition;
use App\Support\Settings\SystemSettings;
use Illuminate\Support\Facades\DB;

/**
 * Status transitions (ФО §6.8.4, "набор настраивается") and the escalation threshold (ФО §6.8.3).
 */
final readonly class ManageWorkflow
{
    public const array REQUIRES = ['none', 'reason', 'comment', 'date'];

    public function __construct(private AuthorizationService $authorization, private SystemSettings $settings, private EventJournal $journal) {}

    public function saveTransition(User $actor, string $from, string $to, string $requires, bool $active): StatusTransition
    {
        $this->authorization->authorize($actor, 'tasks.workflow.manage');
        $codes = CatalogItem::query()->ofCatalog('task_statuses')->pluck('code')->all();
        if (! in_array($from, $codes, true) || ! in_array($to, $codes, true) || $from === $to || ! in_array($requires, self::REQUIRES, true)) {
            throw TaskRuleViolation::because('transition_not_allowed');
        }

        return DB::transaction(function () use ($from, $to, $requires, $active): StatusTransition {
            $transition = StatusTransition::query()->firstOrNew(['from_status' => $from, 'to_status' => $to]);
            $old = $transition->exists ? $transition->only(['requires', 'is_active']) : [];
            $transition->fill(['requires' => $requires, 'is_active' => $active])->save();
            $this->journal->record('tasks.workflow.changed', null, $old, ['from' => $from, 'to' => $to, 'requires' => $requires, 'is_active' => $active]);

            return $transition;
        });
    }

    public function setEscalationDays(User $actor, int $days): void
    {
        $this->authorization->authorize($actor, 'tasks.workflow.manage');
        $days = max(1, min(60, $days));

        DB::transaction(function () use ($actor, $days): void {
            $old = (int) $this->settings->get(EscalateTasksCommand::DAYS_KEY, 3);
            $this->settings->put(EscalateTasksCommand::DAYS_KEY, $days, $actor->id);
            $this->journal->record('tasks.workflow.changed', null, ['escalation_days' => $old], ['escalation_days' => $days]);
        });
    }
}
