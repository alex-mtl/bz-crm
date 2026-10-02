<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Geo\FieldSettings;
use App\Filament\Concerns\ChecksPermissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Settings of the field work (`system.settings.manage`): the map provider (ФО §10 — switchable), how many houses
 * an agitator may answer for, who reads a note by default, the limits of location sharing (Д-22).
 */
class FieldSettingsPage extends Page
{
    use ChecksPermissions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'field-settings';

    protected string $view = 'filament.pages.field-settings';

    /** @var array<string, mixed> */
    public array $values = [];

    public string $shareMinutes = '';

    public static function canAccess(): bool
    {
        return static::allows('system.settings.manage');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.field');
    }

    public static function getNavigationLabel(): string
    {
        return __('geo.ui.settings');
    }

    public function getTitle(): string
    {
        return __('geo.ui.settings');
    }

    public function mount(): void
    {
        $this->values = app(FieldSettings::class)->all();
        $this->shareMinutes = implode(', ', $this->values['share_minutes']);
    }

    public function save(): void
    {
        static::attempt(fn () => app(FieldSettings::class)->update(static::actor(), [
            ...$this->values,
            'share_minutes' => array_filter(array_map('trim', explode(',', $this->shareMinutes)), fn (string $value): bool => $value !== ''),
        ]), __('admin.saved'));
        $this->mount();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['providers' => array_keys(FieldSettings::PROVIDERS)];
    }
}
