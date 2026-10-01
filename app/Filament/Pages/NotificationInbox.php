<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Notifications\Announcements;
use App\Domain\Notifications\Models\Announcement;
use App\Domain\Notifications\NotificationCategories;
use App\Domain\Notifications\NotificationCenter;
use App\Filament\Concerns\ChecksPermissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Url;

/**
 * The notification center (ФО §6.13): everything the person was told, read and unread, by category.
 * Only the person's own notifications — the page has no way to ask for somebody else's.
 */
class NotificationInbox extends Page
{
    use ChecksPermissions;

    private const int PAGE = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'notification-center';

    protected string $view = 'filament.pages.notification-inbox';

    #[Url]
    public bool $unread = false;

    #[Url]
    public string $category = '';

    public int $limit = self::PAGE;

    public static function canAccess(): bool
    {
        return static::allows('notifications.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.notifications');
    }

    public static function getNavigationLabel(): string
    {
        return __('notifications.ui.center');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(NotificationCenter::class)->unreadCount(static::actor());

        return $count > 0 ? (string) $count : null;
    }

    public function getTitle(): string
    {
        return __('notifications.ui.center');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $actor = static::actor();
        $center = app(NotificationCenter::class);
        $categories = app(NotificationCategories::class);
        $items = $center->for($actor, $this->unread, $this->category)->limit($this->limit + 1)->get();

        return [
            'items' => $items->take($this->limit),
            'hasMore' => $items->count() > $this->limit,
            'unreadCount' => $center->unreadCount($actor),
            'categories' => collect($center->categoriesOf($actor))->filter(fn (string $code): bool => $categories->has($code))
                ->mapWithKeys(fn (string $code): array => [$code => $categories->label($code)])->all(),
            'pending' => app(Announcements::class)->pendingFor($actor)->pluck('id')->flip(),
        ];
    }

    public function more(): void
    {
        $this->limit += self::PAGE;
    }

    public function open(string $id): mixed
    {
        $notification = app(NotificationCenter::class)->markRead(static::actor(), $id);
        $url = $notification instanceof DatabaseNotification ? ($notification->data['actions'][0]['url'] ?? null) : null;

        return is_string($url) && $url !== '' && ! str_ends_with($url, '/notification-center') ? $this->redirect($url) : null;
    }

    public function markRead(string $id): void
    {
        app(NotificationCenter::class)->markRead(static::actor(), $id);
    }

    public function markAllRead(): void
    {
        app(NotificationCenter::class)->markAllRead(static::actor());
    }

    public function acknowledge(int $announcementId): void
    {
        static::attempt(fn () => app(Announcements::class)->acknowledge(static::actor(), Announcement::query()->findOrFail($announcementId)), __('notifications.ui.acknowledged'));
    }
}
