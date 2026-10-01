<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Notifications\NotificationCategories;
use App\Domain\Notifications\Preferences;
use App\Filament\Concerns\ChecksPermissions;
use Filament\Pages\Page;

/**
 * "What and where" (ФО §6.13): the person's own settings, category by channel. Mandatory categories are shown
 * and cannot be changed; push is listed and off until mobile clients are decided (Д-23).
 */
class NotificationSettings extends Page
{
    use ChecksPermissions;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'notification-settings';

    protected string $view = 'filament.pages.notification-settings';

    public static function canAccess(): bool
    {
        return static::allows('notifications.preferences');
    }

    public function getTitle(): string
    {
        return __('notifications.ui.settings');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $categories = app(NotificationCategories::class);

        return [
            'matrix' => app(Preferences::class)->matrix(static::actor()),
            'categories' => collect($categories->all())->map(fn (array $category): array => [...$category, 'label' => $categories->label($category['code'])])->all(),
            'channels' => NotificationCategories::CHANNELS,
        ];
    }

    public function toggle(string $category, string $channel): void
    {
        $preferences = app(Preferences::class);
        static::attempt(fn () => $preferences->set(static::actor(), $category, $channel, ! $preferences->enabled(static::actor(), $category, $channel)));
    }

    public function resetToDefaults(): void
    {
        static::attempt(fn () => app(Preferences::class)->reset(static::actor()), __('admin.saved'));
    }
}
