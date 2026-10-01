<?php

declare(strict_types=1);

namespace App\Filament\Resources\Groups\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\SegmentQuery;
use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupInvitation;
use App\Domain\Groups\Models\GroupJoinRequest;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Projects\Models\Project;
use App\Domain\Social\Feed;
use App\Domain\Social\Models\PostAttachment;
use App\Filament\Pages\GroupJoin;
use App\Filament\Pages\SocialFeed;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use App\Filament\Support\Places;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The page of a group (ФО §6.5): about, members with their roles, requests and invitations for its managers,
 * the files published in the group, the chat of the group. The page asks; ManageGroups decides.
 */
class ViewGroup extends ViewRecord
{
    protected static string $resource = GroupResource::class;

    protected string $view = 'filament.groups.view';

    private function group(): Group
    {
        $record = $this->getRecord();
        assert($record instanceof Group);

        return $record;
    }

    private function actor(): User
    {
        $user = Filament::auth()->user();
        assert($user instanceof User);

        return $user;
    }

    public function getTitle(): string
    {
        return $this->group()->name;
    }

    private function attempt(callable $action, ?string $success = null): bool
    {
        try {
            $action();
        } catch (AuthorizationException|DomainException $e) {
            Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

            return false;
        }
        app(GroupAccess::class)->forget();
        if ($success !== null) {
            Notification::make()->title($success)->success()->send();
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $group = $this->group();
        $actor = $this->actor();
        $access = app(GroupAccess::class);
        $manages = $access->canManage($actor, $group);
        $role = $access->roleOf($group, $actor->person_id);
        $postIds = app(Feed::class)->query($actor, Feed::ALL, ['group_id' => $group->id])->select('posts.id');

        return [
            'group' => $group,
            'role' => $role,
            'manages' => $manages,
            'ownerLike' => $role === GroupMember::OWNER || ($role === null && $manages),
            'members' => GroupMember::query()->with('person')->where('group_id', $group->id)
                ->orderByRaw("case role when 'owner' then 0 when 'admin' then 1 when 'moderator' then 2 else 3 end")->orderBy('id')->get(),
            'requests' => $manages ? GroupJoinRequest::query()->with('person')->where('group_id', $group->id)->where('status', GroupJoinRequest::PENDING)->get() : collect(),
            'invitations' => $manages ? GroupInvitation::query()->with('person')->where('group_id', $group->id)->where('status', GroupInvitation::PENDING)->get() : collect(),
            // "Files of the group" are the attachments of the posts published in it — as far as the viewer sees those posts.
            'files' => PostAttachment::query()->whereIn('post_id', $postIds)->latest('id')->limit(50)->get(),
            'postCount' => (clone $postIds)->count(),
            'feedUrl' => SocialFeed::getUrl(['group' => $group->id]),
            'roles' => GroupResource::roles(),
            // The name of the project is shown only to those who may read the project itself.
            'projectName' => $group->project_id !== null
                ? app(AuthorizationService::class)->scopeQuery($actor, 'projects.read', Project::query())->whereKey($group->project_id)->value('name')
                : null,
        ];
    }

    protected function getHeaderActions(): array
    {
        $access = app(GroupAccess::class);
        $isMember = fn (): bool => $access->isMember($this->group(), $this->actor()->person_id);
        $manages = fn (): bool => $access->canManage($this->actor(), $this->group());
        $live = fn (): bool => $this->group()->archived_at === null;
        $groups = app(ManageGroups::class);

        return [
            Action::make('join')
                ->label(fn (): string => __($this->group()->type === Group::OPEN ? 'groups.ui.join' : 'groups.ui.request'))
                ->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => $live() && ! $isMember() && app(AuthorizationService::class)->can($this->actor(), 'groups.join'))
                ->action(fn () => $this->attempt(
                    fn () => $groups->join($this->actor(), $this->group()),
                    __($this->group()->type === Group::OPEN ? 'groups.ui.joined' : 'groups.ui.request_sent'),
                )),
            Action::make('invite')->label(__('groups.ui.invite'))->icon('heroicon-o-envelope')
                ->visible(fn (): bool => $live() && $manages())
                ->schema([
                    Select::make('person_id')->label(__('groups.ui.person'))->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, null, true))
                        ->getOptionLabelUsing(fn ($value): ?string => PersonSearch::label($value)),
                ])
                ->action(fn (array $data) => $this->attempt(
                    fn () => $groups->invite($this->actor(), $this->group(), Person::query()->findOrFail($data['person_id'])),
                    __('groups.ui.invited'),
                )),
            ActionGroup::make([
                Action::make('edit')->label(__('groups.ui.edit'))->icon('heroicon-o-pencil-square')
                    ->visible($manages)
                    ->fillForm(fn (): array => $this->group()->only(['name', 'type', 'description', 'rules', 'org_unit_id', 'territory_id', 'project_id']))
                    ->schema(GroupResource::fields())
                    ->action(fn (array $data) => GroupResource::save($data, $this->group())),
                Action::make('inviteBulk')->label(__('groups.ui.invite_bulk'))->icon('heroicon-o-users')
                    ->visible(fn (): bool => $live() && $manages() && app(AuthorizationService::class)->can($this->actor(), 'groups.invite.bulk'))
                    ->modalDescription(__('groups.ui.invite_bulk_hint'))
                    ->schema([
                        Places::unit('unit_id'),
                        Places::territory(),
                        Select::make('person_types')->label(__('admin.fields.person_type'))->multiple()->options(fn (): array => Options::catalog('person_types')),
                    ])
                    ->action(function (array $data) use ($groups): void {
                        $count = 0;
                        $done = $this->attempt(function () use ($data, $groups, &$count): void {
                            $query = app(SegmentQuery::class);
                            /** @var Builder<Person> $people */
                            $people = $query->build($query->clean($data));
                            $count = $groups->inviteBulk($this->actor(), $this->group(), $people);
                        });
                        if ($done) {
                            Notification::make()->title(__('groups.ui.invited_count', ['count' => $count]))->success()->send();
                        }
                    }),
                Action::make('link')->label(__('groups.ui.invite_link'))->icon('heroicon-o-link')
                    ->visible(fn (): bool => $live() && $manages())
                    ->schema([
                        DateTimePicker::make('expires_at')->label(__('groups.ui.link_expires'))->seconds(false)->default(fn (): Carbon => now()->addDays(7)),
                        TextInput::make('max_uses')->label(__('groups.ui.link_max_uses'))->numeric()->integer()->minValue(1),
                    ])
                    ->action(function (array $data) use ($groups): void {
                        $this->attempt(function () use ($data, $groups): void {
                            $link = $groups->inviteByLink($this->actor(), $this->group(),
                                filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null,
                                filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null);
                            // The token is shown once: only its hash is stored.
                            Notification::make()->title(__('groups.ui.link_created'))->body(GroupJoin::getUrl(['token' => $link['token']]))
                                ->success()->persistent()->send();
                        });
                    }),
                Action::make('leave')->label(__('groups.ui.leave'))->icon('heroicon-o-arrow-right-on-rectangle')->color('gray')
                    ->visible($isMember)->requiresConfirmation()
                    ->action(function () use ($groups) {
                        $left = $this->attempt(fn () => $groups->leave($this->actor(), $this->group()), __('groups.ui.left'));

                        return $left ? redirect(GroupResource::getUrl('index')) : null;
                    }),
                Action::make('archive')->label(__('groups.ui.archive'))->icon('heroicon-o-archive-box')->color('danger')
                    ->visible(fn (): bool => $live() && $manages())->requiresConfirmation()
                    ->action(fn () => $this->attempt(fn () => $groups->archive($this->actor(), $this->group()), __('admin.saved'))),
            ]),
        ];
    }

    public function setRole(int $personId, string $role): void
    {
        $this->attempt(fn () => app(ManageGroups::class)->setRole($this->actor(), $this->group(), Person::query()->findOrFail($personId), $role), __('admin.saved'));
    }

    public function removeMember(int $personId): void
    {
        $this->attempt(fn () => app(ManageGroups::class)->removeMember($this->actor(), $this->group(), Person::query()->findOrFail($personId)), __('admin.saved'));
    }

    public function decide(int $requestId, bool $approve): void
    {
        $this->attempt(fn () => app(ManageGroups::class)->decideRequest(
            $this->actor(), GroupJoinRequest::query()->where('group_id', $this->group()->id)->findOrFail($requestId), $approve,
        ), __('admin.saved'));
    }

    public function revoke(int $invitationId): void
    {
        $this->attempt(fn () => app(ManageGroups::class)->revokeInvitation(
            $this->actor(), GroupInvitation::query()->where('group_id', $this->group()->id)->findOrFail($invitationId),
        ), __('admin.saved'));
    }
}
