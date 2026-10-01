<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Models\GroupInvitation;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Resources\Groups\GroupResource;
use Filament\Pages\Page;

/**
 * Joining a group by an invitation link (ФО §6.5). Opening the link changes nothing: the person confirms.
 */
class GroupJoin extends Page
{
    use ChecksPermissions;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'groups/join/{token}';

    protected string $view = 'filament.pages.group-join';

    public string $token = '';

    public static function canAccess(): bool
    {
        return static::allows('groups.join');
    }

    public function getTitle(): string
    {
        return __('groups.ui.join_by_link');
    }

    public function mount(string $token): void
    {
        $this->token = $token;
    }

    public function getInvitationProperty(): ?GroupInvitation
    {
        $invitation = GroupInvitation::query()->with('group')->where('token_hash', GroupInvitation::hashToken($this->token))->first();

        return $invitation !== null && $invitation->isUsable() && $invitation->group->archived_at === null ? $invitation : null;
    }

    public function join(): void
    {
        $member = null;
        static::attempt(function () use (&$member): void {
            $member = app(ManageGroups::class)->joinByLink(static::actor(), $this->token);
        }, __('groups.ui.joined'));
        if ($member !== null) {
            $this->redirect(GroupResource::getUrl('view', ['record' => $member->group_id]));
        }
    }
}
