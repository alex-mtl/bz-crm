<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Identity\Models\User;
use App\Domain\Profiles\Actions\ManageConfidentialLayers;
use App\Domain\Profiles\ProfileAccess;
use App\Domain\Tasks\Actions\ManageWorkflow;
use App\Domain\Tasks\Console\EscalateTasksCommand;
use App\Domain\Tasks\Models\StatusTransition;
use App\Filament\Support\Options;
use App\Support\Settings\SystemSettings;
use BackedEnum;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Phase 2 settings without code changes: status transitions (ФО §6.8.4), escalation threshold (ФО §6.8.3),
 * about whom "360" notes may be written (Д-14).
 */
class WorkSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 52;

    protected string $view = 'filament.pages.work-settings';

    public string $notes360Scope = ProfileAccess::NOTES360_SCOPE_OWN_UNIT;

    public int $escalationDays = 3;

    public string $newFrom = '';

    public string $newTo = '';

    public string $newRequires = 'none';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $authorization = app(AuthorizationService::class);

        return $user instanceof User && ($authorization->can($user, 'tasks.workflow.manage') || $authorization->can($user, 'system.settings.manage'));
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.work_settings.title');
    }

    public function getTitle(): string
    {
        return __('admin.work_settings.title');
    }

    public function mount(): void
    {
        $this->notes360Scope = app(ProfileAccess::class)->notes360Scope();
        $this->escalationDays = (int) app(SystemSettings::class)->get(EscalateTasksCommand::DAYS_KEY, 3);
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    public function may(string $code): bool
    {
        return app(AuthorizationService::class)->can($this->actor(), $code);
    }

    /**
     * @return array<string, string>
     */
    public function getStatusesProperty(): array
    {
        return Options::catalog('task_statuses');
    }

    /**
     * @return list<StatusTransition>
     */
    public function getTransitionsProperty(): array
    {
        return StatusTransition::query()->orderBy('from_status')->orderBy('to_status')->get()->all();
    }

    public function saveNotesScope(): void
    {
        $this->attempt(fn () => app(ManageConfidentialLayers::class)->setNotes360Scope($this->actor(), $this->notes360Scope));
    }

    public function saveEscalation(): void
    {
        $this->attempt(fn () => app(ManageWorkflow::class)->setEscalationDays($this->actor(), $this->escalationDays));
    }

    public function toggle(int $id): void
    {
        $t = StatusTransition::query()->findOrFail($id);
        $this->attempt(fn () => app(ManageWorkflow::class)->saveTransition($this->actor(), $t->from_status, $t->to_status, $t->requires, ! $t->is_active));
    }

    public function addTransition(): void
    {
        $this->attempt(fn () => app(ManageWorkflow::class)->saveTransition($this->actor(), $this->newFrom, $this->newTo, $this->newRequires, true));
        $this->reset(['newFrom', 'newTo', 'newRequires']);
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
            Notification::make()->title(__('admin.saved'))->success()->send();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
