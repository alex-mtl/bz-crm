<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Models\Role;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Notifications\Preferences;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Options;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * The defaults of notifications by role (ФО §6.13 "с умолчаниями по ролям"): for each role — on, off, or "as the
 * category says". A person's own choice always wins over these.
 */
class NotificationDefaults extends Page
{
    use ChecksPermissions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'notification-defaults';

    protected string $view = 'filament.pages.notification-defaults';

    #[Url]
    public string $role = 'employee';

    public static function canAccess(): bool
    {
        return static::allows('notifications.defaults.manage');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.notifications');
    }

    public static function getNavigationLabel(): string
    {
        return __('notifications.ui.defaults');
    }

    public function getTitle(): string
    {
        return __('notifications.ui.defaults');
    }

    private function selected(): Role
    {
        return Role::query()->where('code', $this->role)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $categories = app(NotificationCategories::class);

        return [
            'roles' => Options::roles(),
            'stored' => app(Preferences::class)->defaultsOf($this->selected()),
            'categories' => collect($categories->all())->map(fn (array $category): array => [...$category, 'label' => $categories->label($category['code'])])->all(),
            'channels' => NotificationCategories::DELIVERABLE,
        ];
    }

    /**
     * @param  string  $value  "on", "off" or "" (as the category says)
     */
    public function setDefault(string $category, string $channel, string $value): void
    {
        static::attempt(fn () => app(Preferences::class)->setDefault(static::actor(), $this->selected(), $category, $channel, match ($value) {
            'on' => true, 'off' => false, default => null,
        }));
    }
}
