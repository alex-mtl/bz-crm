<?php

declare(strict_types=1);

namespace App\Filament\Resources\Groups\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Models\GroupInvitation;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Groups\GroupResource;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    protected function getHeaderActions(): array
    {
        return [
            // A secret group shows up before joining only here — as a personal invitation.
            Action::make('invitations')
                ->label(fn (): string => __('groups.ui.my_invitations').' ('.app(ManageGroups::class)->invitationsFor($this->actor())->count().')')
                ->icon('heroicon-o-envelope')->color('gray')
                ->visible(fn (): bool => app(ManageGroups::class)->invitationsFor($this->actor())->exists())
                ->modalHeading(__('groups.ui.my_invitations'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('social.ui.close'))
                ->modalContent(fn (): View => view('filament.groups.invitations', [
                    'invitations' => app(ManageGroups::class)->invitationsFor($this->actor())->with('inviter')->get(),
                ])),
            Action::make('create')->label(__('groups.ui.create'))->icon('heroicon-o-plus')
                ->visible(fn (): bool => app(AuthorizationService::class)->can($this->actor(), 'groups.create'))
                ->schema(GroupResource::fields())
                ->action(function (array $data) {
                    $group = GroupResource::save($data);

                    return $group !== null ? redirect(GroupResource::getUrl('view', ['record' => $group])) : null;
                }),
        ];
    }

    public function answerInvitation(int $invitationId, bool $accept): void
    {
        try {
            $invitation = GroupInvitation::query()->findOrFail($invitationId);
            app(ManageGroups::class)->answerInvitation($this->actor(), $invitation, $accept);
            Notification::make()->title(__($accept ? 'groups.ui.joined' : 'groups.ui.declined'))->success()->send();
            if ($accept) {
                $this->redirect(GroupResource::getUrl('view', ['record' => $invitation->group_id]));
            }
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();
        }
    }
}
