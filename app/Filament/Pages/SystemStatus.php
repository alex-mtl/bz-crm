<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Files\AntivirusProtection;
use App\Filament\Concerns\ChecksPermissions;
use App\Infrastructure\Health\HealthChecker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SystemStatus extends Page
{
    use ChecksPermissions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.system-status';

    /** @var array<string, bool> */
    public array $checks = [];

    public ?int $failedJobs = null;

    public bool $antivirusEnabled = false;

    public bool $antivirusReachable = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('system.status.read') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('system_status.title');
    }

    public function getTitle(): string
    {
        return __('system_status.title');
    }

    public function mount(): void
    {
        $this->refreshChecks();
    }

    public function refreshChecks(): void
    {
        $checker = app(HealthChecker::class);
        $this->checks = $checker->run();
        $this->failedJobs = $checker->failedJobsCount();

        $antivirus = app(AntivirusProtection::class);
        $this->antivirusEnabled = $antivirus->enabled();
        $this->antivirusReachable = $antivirus->reachable();
    }

    public function canSwitchAntivirus(): bool
    {
        return static::allows('system.settings.manage');
    }

    /**
     * Д-28: the super admin turns the antivirus check of uploaded files on and off. The domain re-checks the
     * right, refuses to turn it on while the service is silent, and journals the change.
     */
    public function switchAntivirusAction(): Action
    {
        return Action::make('switchAntivirus')
            ->label(fn (): string => $this->antivirusEnabled ? __('system_status.antivirus_disable') : __('system_status.antivirus_enable'))
            ->color(fn (): string => $this->antivirusEnabled ? 'danger' : 'primary')
            ->size('sm')
            ->visible(fn (): bool => $this->canSwitchAntivirus())
            ->requiresConfirmation(fn (): bool => $this->antivirusEnabled)
            ->modalDescription(fn (): ?string => $this->antivirusEnabled ? __('system_status.antivirus_confirm_disable') : null)
            ->action(function (): void {
                $turnOn = ! app(AntivirusProtection::class)->enabled();
                static::attempt(
                    fn () => app(AntivirusProtection::class)->set(static::actor(), $turnOn),
                    $turnOn ? __('system_status.antivirus_enabled') : __('system_status.antivirus_disabled'),
                );
                $this->refreshChecks();
            });
    }
}
