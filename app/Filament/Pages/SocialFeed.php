<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\People\Models\Person;
use App\Domain\Social\Actions\ManageComments;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Feed;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostPin;
use App\Domain\Social\Moderation;
use App\Domain\Social\PostCards;
use App\Domain\Social\PostVisibility;
use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Support\Options;
use App\Filament\Support\PersonSearch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

/**
 * The feed of the social network (ФО §6.4). The page only shows and asks: which posts are visible is decided by
 * Feed / PostVisibility, and every button calls a domain action that checks the right again.
 */
class SocialFeed extends Page
{
    use ChecksPermissions;

    private const int PAGE = 15;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'feed';

    protected string $view = 'filament.pages.social-feed';

    #[Url]
    public string $mode = Feed::ALL;

    #[Url]
    public string $search = '';

    #[Url(as: 'group')]
    public ?int $groupId = null;

    #[Url(as: 'territory')]
    public ?int $territoryId = null;

    #[Url(as: 'post')]
    public ?int $postId = null;

    public int $limit = self::PAGE;

    /** @var array<int, bool> posts whose comments are unfolded */
    public array $open = [];

    public static function canAccess(): bool
    {
        return static::allows('posts.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.social');
    }

    public static function getNavigationLabel(): string
    {
        return __('social.ui.feed');
    }

    public function getTitle(): string
    {
        return __('social.ui.feed');
    }

    public function mount(): void
    {
        if (! in_array($this->mode, Feed::MODES, true)) {
            $this->mode = Feed::ALL;
        }
        if ($this->postId !== null) {
            $this->open[$this->postId] = true;
        }
    }

    /**
     * @return Collection<int, Post>
     */
    public function getPostsProperty(): Collection
    {
        if ($this->postId !== null) {
            return app(PostVisibility::class)->visibleTo(static::actor(), true)->whereKey($this->postId)->get();
        }

        return app(Feed::class)->query(static::actor(), $this->mode, [
            'group_id' => $this->groupId, 'territory_id' => $this->territoryId, 'search' => $this->search,
        ])->limit($this->limit + 1)->get();
    }

    /**
     * @return array<string, string>
     */
    public function getModesProperty(): array
    {
        return collect(Feed::MODES)->mapWithKeys(fn (string $mode): array => [$mode => __('social.ui.modes.'.$mode)])->all();
    }

    /**
     * @return array<int, string>
     */
    public function getMyGroupsProperty(): array
    {
        return Group::query()->whereKey(app(GroupAccess::class)->groupIdsOf(static::actor()->person_id))
            ->whereNull('archived_at')->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, array{name: string, symbol: string}>
     */
    public function getReactionTypesProperty(): array
    {
        return CatalogItem::query()->ofCatalog('reaction_types')->selectable()->get()
            ->mapWithKeys(fn (CatalogItem $item): array => [$item->code => ['name' => $item->name(), 'symbol' => (string) $item->property('symbol', '•')]])->all();
    }

    /**
     * Everything the template needs, computed once per render.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $actor = static::actor();
        $posts = $this->getPostsProperty();
        $hasMore = $this->postId === null && $posts->count() > $this->limit;
        $posts = $posts->take($this->limit)->values();
        $moderation = app(Moderation::class);
        $groups = app(GroupAccess::class);

        $may = [];
        $threads = [];
        $commentReactions = [];
        foreach ($posts as $post) {
            $own = $post->author_person_id === $actor->person_id;
            $moderates = ! $own && $moderation->mayModerate($actor, $post);
            $may[$post->id] = [
                'own' => $own,
                'moderate' => $moderates,
                'revisions' => $post->edited_at !== null && ($own || $moderation->mayModerate($actor, $post, 'moderation.revisions.read')),
                'pin' => $post->isPublished() && $post->visibility !== Post::PRIVATE && (static::allows('posts.pin')
                    || $post->groups->contains(fn (Group $group): bool => $groups->canManage($actor, $group))),
            ];
            if ($this->open[$post->id] ?? false) {
                $threads[$post->id] = app(ManageComments::class)->thread($actor, $post);
                $commentReactions += app(PostCards::class)->commentReactions($actor, $threads[$post->id]);
            }
        }

        return [
            'posts' => $posts,
            'hasMore' => $hasMore,
            'cards' => app(PostCards::class)->for($actor, $posts),
            'may' => $may,
            'threads' => $threads,
            'commentReactions' => $commentReactions,
            'actorPersonId' => $actor->person_id,
            'mutedUntil' => $moderation->mutedUntil($actor->person_id),
            'canCreate' => static::allows('posts.create'),
            'canComment' => static::allows('comments.create'),
        ];
    }

    public function more(): void
    {
        $this->limit += self::PAGE;
    }

    public function showAll(): void
    {
        $this->postId = null;
    }

    public function toggleComments(int $postId): void
    {
        $this->open[$postId] = ! ($this->open[$postId] ?? false);
    }

    public function react(string $type, int $id, string $code): void
    {
        $target = $type === 'comment' ? Comment::query()->find($id) : Post::query()->find($id);
        if ($target !== null) {
            static::attempt(fn () => app(ManageComments::class)->react(static::actor(), $target, $code));
        }
    }

    public function vote(int $postId, int $optionId): void
    {
        static::attempt(fn () => app(ManagePosts::class)->vote(static::actor(), Post::query()->findOrFail($postId), $optionId));
    }

    public function toggleFollow(int $personId): void
    {
        $author = Person::query()->findOrFail($personId);
        $following = app(ManageComments::class)->isFollowing(static::actor(), $author);
        static::attempt(fn () => $following
            ? app(ManageComments::class)->unfollow(static::actor(), $author)
            : app(ManageComments::class)->follow(static::actor(), $author));
    }

    public function publishNow(int $postId): void
    {
        static::attempt(fn () => app(ManagePosts::class)->publish(static::actor(), Post::query()->findOrFail($postId)), __('social.ui.published'));
    }

    public function deletePost(int $postId): void
    {
        static::attempt(fn () => app(ManagePosts::class)->delete(static::actor(), Post::query()->findOrFail($postId)), __('social.ui.deleted'));
    }

    public function deleteComment(int $commentId): void
    {
        static::attempt(fn () => app(ManageComments::class)->delete(static::actor(), Comment::query()->findOrFail($commentId)));
    }

    public function restore(string $type, int $id): void
    {
        $target = $type === 'comment' ? Comment::query()->findOrFail($id) : Post::query()->findOrFail($id);
        static::attempt(fn () => app(Moderation::class)->restore(static::actor(), $target), __('social.ui.restored'));
    }

    public function unpin(int $pinId): void
    {
        static::attempt(fn () => app(ManagePosts::class)->unpin(static::actor(), PostPin::query()->findOrFail($pinId)));
    }

    /**
     * @return array<string, string>
     */
    private function visibilityOptions(): array
    {
        return array_filter([
            Post::PUBLIC => static::allows('posts.publish.global') ? __('social.visibility.public') : null,
            Post::REGIONAL => static::allows('posts.publish.region') ? __('social.visibility.regional') : null,
            Post::GROUP => $this->getMyGroupsProperty() !== [] ? __('social.visibility.group') : null,
            Post::TARGETED => __('social.visibility.targeted'),
            Post::PRIVATE => __('social.visibility.private'),
        ]);
    }

    /**
     * The audience pickers. They only offer; ManagePosts decides what the author may really address.
     *
     * @return list<Component>
     */
    private function audienceFields(): array
    {
        return [
            Select::make('visibility')->label(__('social.ui.visibility'))->options(fn (): array => $this->visibilityOptions())
                ->required()->default(Post::PRIVATE)->live(),
            Select::make('territory_ids')->label(__('social.ui.territories'))->multiple()->searchable()->required()
                ->visible(fn (Get $get): bool => $get('visibility') === Post::REGIONAL)
                ->getSearchResultsUsing(fn (string $search): array => Territory::query()->search($search)->limit(60)->get()
                    ->filter(fn (Territory $territory): bool => app(PostVisibility::class)->coversTerritory(static::actor(), $territory))
                    ->take(30)->mapWithKeys(fn (Territory $territory): array => [$territory->id => $territory->name()])->all())
                ->getOptionLabelsUsing(fn (array $values): array => Territory::query()->whereKey($values)->get()
                    ->mapWithKeys(fn (Territory $territory): array => [$territory->id => $territory->name()])->all()),
            Select::make('group_ids')->label(__('social.ui.groups'))->multiple()->required()
                ->options(fn (): array => $this->getMyGroupsProperty())
                ->visible(fn (Get $get): bool => $get('visibility') === Post::GROUP),
            Select::make('person_ids')->label(__('social.ui.people'))->multiple()->searchable()
                ->visible(fn (Get $get): bool => $get('visibility') === Post::TARGETED)
                ->getSearchResultsUsing(fn (string $search): array => PersonSearch::search($search, null, true))
                ->getOptionLabelsUsing(fn (array $values): array => Person::query()->whereKey($values)->get()
                    ->mapWithKeys(fn (Person $person): array => [$person->id => $person->fullName()])->all()),
            Select::make('role_codes')->label(__('social.ui.roles'))->multiple()->options(fn (): array => Options::roles())
                ->visible(fn (Get $get): bool => $get('visibility') === Post::TARGETED && static::allows('posts.publish.global')),
        ];
    }

    public function composeAction(): Action
    {
        return Action::make('compose')
            ->modalHeading(fn (array $arguments): string => __(isset($arguments['repost']) ? 'social.ui.repost' : 'social.ui.new_post'))
            ->modalSubmitActionLabel(__('social.ui.save'))
            ->fillForm(fn (): array => array_filter([
                'visibility' => $this->groupId !== null && isset($this->getMyGroupsProperty()[$this->groupId]) ? Post::GROUP : Post::PRIVATE,
                'group_ids' => $this->groupId !== null && isset($this->getMyGroupsProperty()[$this->groupId]) ? [$this->groupId] : null,
                'intent' => ManagePosts::INTENT_NOW,
            ]))
            ->schema(fn (array $arguments): array => [
                Textarea::make('body')->label(__('social.ui.text'))->rows(5)->maxLength(10000),
                ...$this->audienceFields(),
                FileUpload::make('files')->label(__('social.ui.attachments'))->multiple()->maxFiles(10)->maxSize(20480)
                    ->disk('local')->directory('social/incoming')->storeFileNamesIn('file_names')
                    ->hidden(isset($arguments['repost'])),
                TagsInput::make('poll_options')->label(__('social.ui.poll'))->helperText(__('social.ui.poll_hint'))
                    ->hidden(isset($arguments['repost'])),
                Select::make('intent')->label(__('social.ui.when'))->required()->default(ManagePosts::INTENT_NOW)->live()
                    ->visible(fn (): bool => static::allows('posts.schedule'))
                    ->options([
                        ManagePosts::INTENT_NOW => __('social.ui.intent.now'),
                        ManagePosts::INTENT_DRAFT => __('social.ui.intent.draft'),
                        ManagePosts::INTENT_SCHEDULE => __('social.ui.intent.schedule'),
                    ]),
                DateTimePicker::make('publish_at')->label(__('social.ui.publish_at'))->seconds(false)->required()
                    ->visible(fn (Get $get): bool => $get('intent') === ManagePosts::INTENT_SCHEDULE),
            ])
            ->action(function (array $arguments, array $data): void {
                $paths = array_values((array) ($data['files'] ?? []));
                $names = (array) ($data['file_names'] ?? []);
                $files = array_map(fn (string $path): array => [
                    'source' => Storage::disk('local')->path($path), 'name' => (string) ($names[$path] ?? basename($path)),
                ], $paths);

                $created = static::attempt(fn () => app(ManagePosts::class)->create(static::actor(), [
                    ...$data,
                    'publish_at' => isset($data['publish_at']) ? Carbon::parse($data['publish_at']) : null,
                    'repost_of_post_id' => $arguments['repost'] ?? null,
                ], $files), __('social.ui.saved'));
                Storage::disk('local')->delete($paths);
                if ($created && ($data['intent'] ?? ManagePosts::INTENT_NOW) !== ManagePosts::INTENT_NOW) {
                    $this->mode = Feed::MINE;
                }
            });
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->modalHeading(__('social.ui.edit'))
            ->fillForm(fn (array $arguments): array => ['body' => Post::query()->find($arguments['post'] ?? 0)?->body])
            ->schema([Textarea::make('body')->label(__('social.ui.text'))->rows(6)->maxLength(10000)])
            ->action(fn (array $arguments, array $data) => static::attempt(
                fn () => app(ManagePosts::class)->update(static::actor(), Post::query()->findOrFail($arguments['post'] ?? 0), $data['body'] ?? null),
                __('social.ui.saved'),
            ));
    }

    public function audienceAction(): Action
    {
        return Action::make('audience')
            ->modalHeading(__('social.ui.change_audience'))
            ->modalDescription(__('social.ui.change_audience_hint'))
            ->schema(fn (): array => $this->audienceFields())
            ->action(fn (array $arguments, array $data) => static::attempt(
                fn () => app(ManagePosts::class)->changeAudience(static::actor(), Post::query()->findOrFail($arguments['post'] ?? 0), $data),
                __('social.ui.saved'),
            ));
    }

    public function commentAction(): Action
    {
        return Action::make('comment')
            ->modalHeading(fn (array $arguments): string => __(isset($arguments['parent']) ? 'social.ui.reply' : 'social.ui.comment'))
            ->modalDescription(fn (array $arguments): ?string => isset($arguments['quoted'])
                ? '« '.str((string) Comment::query()->whereKey($arguments['quoted'])->whereNull('deleted_at')->whereNull('hidden_at')->value('body'))->limit(160).' »' : null)
            ->schema([Textarea::make('body')->label(__('social.ui.text'))->rows(3)->required()->maxLength(5000)])
            ->action(function (array $arguments, array $data): void {
                $postId = (int) ($arguments['post'] ?? 0);
                static::attempt(fn () => app(ManageComments::class)->add(
                    static::actor(), Post::query()->findOrFail($postId), (string) $data['body'],
                    isset($arguments['parent']) ? Comment::query()->findOrFail($arguments['parent']) : null,
                    isset($arguments['quoted']) ? Comment::query()->findOrFail($arguments['quoted']) : null,
                ));
                $this->open[$postId] = true;
            });
    }

    public function reportAction(): Action
    {
        return Action::make('report')
            ->modalHeading(__('social.ui.report'))
            ->schema([
                Select::make('reason')->label(__('social.ui.reason'))->options(fn (): array => Options::catalog('report_reasons'))->required(),
                Textarea::make('comment')->label(__('social.ui.report_comment'))->rows(2)->maxLength(1000),
            ])
            ->action(fn (array $arguments, array $data) => static::attempt(
                fn () => app(Moderation::class)->report(static::actor(), $this->target($arguments), (string) $data['reason'], $data['comment'] ?? null),
                __('social.ui.reported'),
            ));
    }

    public function hideAction(): Action
    {
        return Action::make('hide')
            ->modalHeading(__('social.ui.hide'))
            ->modalDescription(__('social.ui.hide_hint'))
            ->color('danger')
            ->schema([Textarea::make('reason')->label(__('social.ui.reason'))->rows(2)->required()->maxLength(500)])
            ->action(fn (array $arguments, array $data) => static::attempt(
                fn () => app(Moderation::class)->hide(static::actor(), $this->target($arguments), (string) $data['reason']),
                __('social.ui.hidden_done'),
            ));
    }

    public function pinAction(): Action
    {
        return Action::make('pin')
            ->modalHeading(__('social.ui.pin'))
            ->schema(function (array $arguments): array {
                $post = Post::query()->with(['territories', 'groups'])->find($arguments['post'] ?? 0);
                $managed = $post?->groups->filter(fn (Group $group): bool => app(GroupAccess::class)->canManage(static::actor(), $group)) ?? collect();

                return [
                    Select::make('scope')->label(__('social.ui.pin_where'))->required()->live()->options(array_filter([
                        PostPin::GLOBAL => $post?->visibility === Post::PUBLIC ? __('social.ui.pin_scopes.global') : null,
                        PostPin::TERRITORY => in_array($post?->visibility, [Post::PUBLIC, Post::REGIONAL], true) ? __('social.ui.pin_scopes.territory') : null,
                        PostPin::GROUP => $managed->isNotEmpty() ? __('social.ui.pin_scopes.group') : null,
                    ])),
                    Select::make('territory_id')->label(__('admin.territories.singular'))->searchable()->required()
                        ->visible(fn (Get $get): bool => $get('scope') === PostPin::TERRITORY)
                        ->options(fn (): array => $post?->territories->mapWithKeys(fn (Territory $t): array => [$t->id => $t->name()])->all() ?? [])
                        ->getSearchResultsUsing(fn (string $search): array => Territory::query()->search($search)->limit(30)->get()
                            ->mapWithKeys(fn (Territory $t): array => [$t->id => $t->name()])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Territory::query()->find($value)?->name()),
                    Select::make('group_id')->label(__('groups.ui.singular'))->required()
                        ->visible(fn (Get $get): bool => $get('scope') === PostPin::GROUP)
                        ->options($managed->pluck('name', 'id')->all()),
                ];
            })
            ->action(fn (array $arguments, array $data) => static::attempt(
                fn () => app(ManagePosts::class)->pin(static::actor(), Post::query()->findOrFail($arguments['post'] ?? 0), (string) $data['scope'], match ($data['scope']) {
                    PostPin::TERRITORY => (int) $data['territory_id'], PostPin::GROUP => (int) $data['group_id'], default => null,
                }),
                __('social.ui.pinned'),
            ));
    }

    public function revisionsAction(): Action
    {
        return Action::make('revisions')
            ->modalHeading(__('social.ui.revisions'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('social.ui.close'))
            ->modalContent(function (array $arguments): View {
                $revisions = collect();
                static::attempt(function () use ($arguments, &$revisions): void {
                    $revisions = app(Moderation::class)->revisions(static::actor(), Post::query()->findOrFail($arguments['post'] ?? 0));
                });

                return view('filament.social.revisions', ['revisions' => $revisions]);
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function target(array $arguments): Post|Comment
    {
        return ($arguments['type'] ?? 'post') === 'comment'
            ? Comment::query()->findOrFail($arguments['id'] ?? 0)
            : Post::query()->findOrFail($arguments['id'] ?? 0);
    }
}
