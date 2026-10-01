<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Infrastructure\Health\HealthChecker;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SystemStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 100;

    protected string $view = 'filament.pages.system-status';

    /** @var array<string, bool> */
    public array $checks = [];

    public ?int $failedJobs = null;

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
    }
}
