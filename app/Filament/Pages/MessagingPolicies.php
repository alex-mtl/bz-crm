<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Models\Role;
use App\Domain\Messaging\DirectMessagePolicy;
use App\Domain\Messaging\Retention;
use App\Filament\Concerns\ChecksPermissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The rules of the messenger kept by the super admin: who may write to whom first (Д-26) and how long messages
 * are kept (Д-27). Every change goes through the domain and is journaled there.
 */
class MessagingPolicies extends Page
{
    use ChecksPermissions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'messaging-policies';

    protected string $view = 'filament.pages.messaging-policies';

    public ?int $retentionMonths = null;

    public static function canAccess(): bool
    {
        return static::allows('messaging.policies.manage');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.messenger');
    }

    public static function getNavigationLabel(): string
    {
        return __('messaging.ui.policies');
    }

    public function getTitle(): string
    {
        return __('messaging.ui.policies');
    }

    public function mount(): void
    {
        $this->retentionMonths = app(Retention::class)->months();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $policy = app(DirectMessagePolicy::class);
        $roles = Role::query()->orderBy('id')->get();
        $matrix = [];
        foreach ($roles as $sender) {
            foreach ($roles as $recipient) {
                $matrix[$sender->id][$recipient->id] = $policy->allowed($sender->id, $recipient->id);
            }
        }

        return ['roles' => $roles, 'matrix' => $matrix, 'current' => app(Retention::class)->months()];
    }

    public function toggle(int $senderRoleId, int $recipientRoleId): void
    {
        $policy = app(DirectMessagePolicy::class);
        static::attempt(fn () => $policy->set(
            static::actor(), Role::query()->findOrFail($senderRoleId), Role::query()->findOrFail($recipientRoleId), ! $policy->allowed($senderRoleId, $recipientRoleId),
        ));
    }

    public function saveRetention(): void
    {
        static::attempt(fn () => app(Retention::class)->set(static::actor(), filled($this->retentionMonths) ? (int) $this->retentionMonths : null), __('admin.saved'));
    }
}
