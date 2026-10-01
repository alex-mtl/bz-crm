<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskPerson;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Personal lists (ФО §6.8.3): my tasks, set by me, watching, overdue — all within the tasks.read scope.
 */
class ListTasks extends ListRecords
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        $user = Filament::auth()->user();
        $personId = $user instanceof User ? $user->person_id : 0;
        $withRole = fn (string $role) => fn (Builder $query) => $query->whereIn('id', TaskPerson::query()->where('person_id', $personId)->where('role', $role)->select('task_id'));

        return [
            'mine' => Tab::make(__('admin.tasks.tabs.mine'))->modifyQueryUsing($withRole(TaskPerson::ASSIGNEE)),
            'created' => Tab::make(__('admin.tasks.tabs.created'))->modifyQueryUsing(fn (Builder $query) => $query->where('creator_person_id', $personId)),
            'watching' => Tab::make(__('admin.tasks.tabs.watching'))->modifyQueryUsing($withRole(TaskPerson::WATCHER)),
            'overdue' => Tab::make(__('admin.tasks.tabs.overdue'))->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('due_at')->where('due_at', '<', now())->whereNotIn('status_code', Task::CLOSED)),
            'all' => Tab::make(__('admin.tasks.tabs.all')),
        ];
    }
}
