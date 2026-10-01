<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Access\TerritorialAccess;
use App\Domain\Audit\EventJournal;
use App\Domain\Geo\Models\Territory;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Retraction;
use App\Domain\People\Models\Person;
use App\Domain\Social\Exceptions\SocialRuleViolation;
use App\Domain\Social\Models\PollOption;
use App\Domain\Social\Models\PollVote;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostAttachment;
use App\Domain\Social\Models\PostPin;
use App\Domain\Social\Models\PostRevision;
use App\Domain\Social\Models\PostTarget;
use App\Domain\Social\Moderation;
use App\Domain\Social\Notifications\SocialNotice;
use App\Domain\Social\PostVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Posts (ФО §6.4.1): the author chooses the audience at the moment of publishing, and may publish only to the
 * audiences their rights reach — own groups, visible people, own territories, the whole organization.
 * Whoever changes the audience later must have the same right for the new audience.
 */
final readonly class ManagePosts
{
    public const string INTENT_NOW = 'now';

    public const string INTENT_DRAFT = 'draft';

    public const string INTENT_SCHEDULE = 'schedule';

    public function __construct(
        private AuthorizationService $authorization,
        private PostVisibility $visibility,
        private GroupAccess $groups,
        private TerritorialAccess $territories,
        private Moderation $moderation,
        private EventJournal $journal,
    ) {}

    /**
     * @param  array{body?: string|null, visibility?: string, territory_ids?: list<int>, group_ids?: list<int>, person_ids?: list<int>,
     *               role_codes?: list<string>, poll_options?: list<string>, intent?: string, publish_at?: Carbon|string|null,
     *               repost_of_post_id?: int|null}  $data
     * @param  list<array{source: string, name: string, mime?: string|null}>  $files  files already on the server (uploads)
     */
    public function create(User $actor, array $data, array $files = []): Post
    {
        $this->authorization->authorize($actor, 'posts.create');
        $intent = $data['intent'] ?? self::INTENT_NOW;
        $body = filled($data['body'] ?? null) ? trim((string) $data['body']) : null;
        $pollOptions = array_values(array_filter(array_map(fn ($option): string => trim((string) $option), $data['poll_options'] ?? [])));
        $original = isset($data['repost_of_post_id']) ? Post::query()->find($data['repost_of_post_id']) : null;

        if ($original !== null && (! $original->isPublished() || $original->isHidden() || ! $this->visibility->canSee($actor, $original))) {
            throw new AuthorizationException(__('access.denied'));
        }
        if ($body === null && $files === [] && $pollOptions === [] && $original === null) {
            throw SocialRuleViolation::because('empty_post');
        }
        if (count($pollOptions) === 1) {
            throw SocialRuleViolation::because('poll_needs_options');
        }
        $audience = $this->audience($actor, $data);
        $publishAt = null;
        if ($intent !== self::INTENT_NOW) {
            $this->authorization->authorize($actor, 'posts.schedule');
        }
        if ($intent === self::INTENT_SCHEDULE) {
            $publishAt = isset($data['publish_at']) ? Carbon::parse($data['publish_at']) : null;
            if ($publishAt === null || ! $publishAt->isFuture()) {
                throw SocialRuleViolation::because('schedule_in_past');
            }
        }
        if ($intent === self::INTENT_NOW) {
            $this->moderation->ensureNotMuted($actor);
        }

        $post = DB::transaction(function () use ($actor, $body, $original, $audience, $pollOptions, $files, $intent, $publishAt): Post {
            $post = Post::query()->create([
                'author_person_id' => $actor->person_id,
                'body' => $body,
                'repost_of_post_id' => $original?->id,
                'visibility' => $audience['visibility'],
                'status' => match ($intent) {
                    self::INTENT_DRAFT => Post::DRAFT, self::INTENT_SCHEDULE => Post::SCHEDULED, default => Post::PUBLISHED,
                },
                'publish_at' => $publishAt,
                'published_at' => $intent === self::INTENT_NOW ? now() : null,
            ]);
            $this->syncAudience($post, $audience);
            foreach ($pollOptions as $index => $text) {
                PollOption::query()->create(['post_id' => $post->id, 'text' => mb_substr($text, 0, 255), 'sort_order' => $index]);
            }
            foreach ($files as $index => $file) {
                $this->attach($post, $file, $index);
            }
            $this->journal->record('social.post.created', $post, [], [
                'status' => $post->status, 'visibility' => $post->visibility, ...$this->audienceForJournal($audience),
            ]);

            return $post;
        });

        if ($post->status === Post::PUBLISHED) {
            $this->announce($post);
        }

        return $post;
    }

    /**
     * Publishes a draft or a scheduled post now.
     */
    public function publish(User $actor, Post $post): Post
    {
        $this->ensureAuthor($actor, $post);
        if ($post->status === Post::PUBLISHED) {
            return $post;
        }
        $this->moderation->ensureNotMuted($actor);
        // Rights may have changed since the draft was written: the audience is checked again.
        $this->audience($actor, $this->currentAudience($post));

        DB::transaction(function () use ($post): void {
            $post->update(['status' => Post::PUBLISHED, 'published_at' => now(), 'publish_at' => null]);
            $this->journal->record('social.post.published', $post, [], ['visibility' => $post->visibility]);
        });
        $this->announce($post);

        return $post;
    }

    /**
     * Scheduled posts whose time has come (scheduler). A post of a muted author waits until the mute ends.
     */
    public function publishDue(): int
    {
        $published = 0;
        foreach (Post::query()->where('status', Post::SCHEDULED)->whereNull('deleted_at')->where('publish_at', '<=', now())->get() as $post) {
            if ($this->moderation->mutedUntil($post->author_person_id) !== null) {
                continue;
            }
            DB::transaction(function () use ($post): void {
                $post->update(['status' => Post::PUBLISHED, 'published_at' => $post->publish_at, 'publish_at' => null]);
                $this->journal->record('social.post.published', $post, [], ['visibility' => $post->visibility, 'scheduled' => true]);
            });
            $this->announce($post);
            $published++;
        }

        return $published;
    }

    /**
     * Edits the text. A published post keeps its previous text in the edit history and is marked "edited".
     */
    public function update(User $actor, Post $post, ?string $body): Post
    {
        // "С": only one's own posts, whatever the scope of the role (catalog §6).
        $this->ensureAuthor($actor, $post);
        $this->authorization->authorize($actor, 'posts.update');
        $body = filled($body) ? trim((string) $body) : null;
        if ($body === $post->body) {
            return $post;
        }
        if ($body === null && ! $post->attachments()->exists() && ! $post->pollOptions()->exists() && $post->repost_of_post_id === null) {
            throw SocialRuleViolation::because('empty_post');
        }

        return DB::transaction(function () use ($actor, $post, $body): Post {
            if ($post->status === Post::PUBLISHED) {
                PostRevision::query()->create(['post_id' => $post->id, 'body' => $post->body, 'edited_by_user_id' => $actor->id]);
                $post->edited_at = now();
            }
            $post->body = $body;
            $post->save();
            // The text of a post is not copied into the journal — only the fact of the edit.
            $this->journal->record('social.post.updated', $post, [], ['revisions' => $post->revisions()->count()]);

            return $post;
        });
    }

    /**
     * Changes who sees the post (ФО §6.4.1: "by the author or a moderator") — only to an audience the one who
     * changes it may publish to themselves.
     *
     * @param  array<string, mixed>  $data  visibility, territory_ids, group_ids, person_ids, role_codes
     */
    public function changeAudience(User $actor, Post $post, array $data): Post
    {
        if ($post->author_person_id !== $actor->person_id) {
            $this->authorization->authorize($actor, 'moderation.hide', $post);
        }
        $audience = $this->audience($actor, $data);

        $post = DB::transaction(function () use ($post, $audience): Post {
            $old = $post->visibility;
            $post->update(['visibility' => $audience['visibility']]);
            $this->syncAudience($post, $audience);
            // A pin outside the new audience would show the post where it no longer belongs.
            PostPin::query()->where('post_id', $post->id)->delete();
            $this->journal->record('social.post.audience_changed', $post, ['visibility' => $old], [
                'visibility' => $post->visibility, ...$this->audienceForJournal($audience),
            ]);

            return $post;
        });
        $this->retractFromThoseWhoLostAccess($post);

        return $post;
    }

    public function delete(User $actor, Post $post): void
    {
        $this->ensureAuthor($actor, $post);

        DB::transaction(function () use ($post): void {
            $post->update(['deleted_at' => now()]);
            PostPin::query()->where('post_id', $post->id)->delete();
            $this->journal->record('social.post.deleted', $post);
        });
        $this->retractFromThoseWhoLostAccess($post);
    }

    /**
     * ТЗ §37: whoever no longer sees the post keeps the fact of a notification about it, not its content.
     */
    private function retractFromThoseWhoLostAccess(Post $post): void
    {
        app(Retraction::class)->retractFromThoseWhoLostAccess('post', $post->id, fn (User $user): bool => $this->visibility->canSee($user, $post));
    }

    public function vote(User $actor, Post $post, int $optionId): void
    {
        if (! $post->isPublished() || $post->isHidden() || ! $this->visibility->canSee($actor, $post)) {
            throw new AuthorizationException(__('access.denied'));
        }
        if (! $post->pollOptions()->whereKey($optionId)->exists()) {
            throw SocialRuleViolation::because('invalid_poll_option');
        }
        PollVote::query()->updateOrCreate(['post_id' => $post->id, 'person_id' => $actor->person_id], ['option_id' => $optionId]);
    }

    /**
     * Pins a post: globally, in a territory or in a group (ФО §6.4.1) — where the one who pins has that right.
     */
    public function pin(User $actor, Post $post, string $scope, ?int $scopeId = null): PostPin
    {
        if (! $post->isPublished() || $post->isHidden() || $post->visibility === Post::PRIVATE || ! $this->visibility->canSee($actor, $post)) {
            throw SocialRuleViolation::because('cannot_pin');
        }
        $this->ensureMayPin($actor, $post, $scope, $scopeId);

        return DB::transaction(function () use ($actor, $post, $scope, $scopeId): PostPin {
            $pin = PostPin::query()->firstOrCreate(
                ['post_id' => $post->id, 'scope' => $scope, 'scope_id' => $scope === PostPin::GLOBAL ? null : $scopeId],
                ['pinned_by_user_id' => $actor->id],
            );
            if ($pin->wasRecentlyCreated) {
                $this->journal->record('social.post.pinned', $post, [], ['scope' => $scope, 'scope_id' => $pin->scope_id]);
            }

            return $pin;
        });
    }

    public function unpin(User $actor, PostPin $pin): void
    {
        $post = Post::query()->findOrFail($pin->post_id);
        $this->ensureMayPin($actor, $post, $pin->scope, $pin->scope_id);

        DB::transaction(function () use ($pin, $post): void {
            $this->journal->record('social.post.unpinned', $post, ['scope' => $pin->scope, 'scope_id' => $pin->scope_id]);
            $pin->delete();
        });
    }

    /**
     * The file of an attachment — for those who see its post (ТЗ §68: never a public link).
     * A hidden post gives its files only to the author and to its moderators.
     */
    public function attachmentPath(User $viewer, PostAttachment $attachment): string
    {
        $post = Post::query()->findOrFail($attachment->post_id);
        $allowed = $this->visibility->canSee($viewer, $post)
            || ($post->isHidden() && $post->deleted_at === null && $this->moderation->mayModerate($viewer, $post));
        if (! $allowed || ! Storage::disk('local')->exists($attachment->path)) {
            throw new AuthorizationException(__('access.denied'));
        }

        return Storage::disk('local')->path($attachment->path);
    }

    /**
     * Checks that the actor may address this audience and returns it normalized.
     *
     * @param  array<string, mixed>  $data
     * @return array{visibility: string, territory_ids: list<int>, group_ids: list<int>, person_ids: list<int>, role_codes: list<string>}
     */
    private function audience(User $actor, array $data): array
    {
        $visibility = (string) ($data['visibility'] ?? Post::PRIVATE);
        $ints = fn (string $key): array => array_values(array_unique(array_map('intval', array_filter((array) ($data[$key] ?? [])))));
        $audience = ['visibility' => $visibility, 'territory_ids' => [], 'group_ids' => [], 'person_ids' => [], 'role_codes' => []];

        switch ($visibility) {
            case Post::PRIVATE:
                break;
            case Post::PUBLIC:
                $this->authorization->authorize($actor, 'posts.publish.global');
                break;
            case Post::REGIONAL:
                $this->authorization->authorize($actor, 'posts.publish.region');
                $audience['territory_ids'] = $ints('territory_ids');
                $territories = Territory::query()->whereKey($audience['territory_ids'])->get();
                if ($territories->isEmpty() || $territories->count() !== count($audience['territory_ids'])) {
                    throw SocialRuleViolation::because('audience_required');
                }
                foreach ($territories as $territory) {
                    // "Т — only one's own territories" (catalog §6).
                    if (! $this->visibility->coversTerritory($actor, $territory)) {
                        throw new AuthorizationException(__('social.errors.territory_out_of_reach', ['name' => $territory->name()]));
                    }
                }
                break;
            case Post::GROUP:
                $audience['group_ids'] = $ints('group_ids');
                $groups = Group::query()->whereKey($audience['group_ids'])->whereNull('archived_at')->get();
                if ($groups->isEmpty() || $groups->count() !== count($audience['group_ids'])) {
                    throw SocialRuleViolation::because('audience_required');
                }
                foreach ($groups as $group) {
                    if (! $this->groups->isMember($group, $actor->person_id)) {
                        throw new AuthorizationException(__('access.denied'));
                    }
                }
                break;
            case Post::TARGETED:
                $audience['person_ids'] = $ints('person_ids');
                $audience['role_codes'] = array_values(array_unique(array_filter(array_map('strval', (array) ($data['role_codes'] ?? [])))));
                if ($audience['person_ids'] === [] && $audience['role_codes'] === []) {
                    throw SocialRuleViolation::because('audience_required');
                }
                foreach (Person::query()->whereKey($audience['person_ids'])->get() as $person) {
                    $this->authorization->authorize($actor, 'people.read', $person);
                }
                if ($audience['role_codes'] !== []) {
                    // A whole role is an organization-wide audience: it takes the right to publish to everyone.
                    $this->authorization->authorize($actor, 'posts.publish.global');
                    if (Role::query()->whereIn('code', $audience['role_codes'])->count() !== count($audience['role_codes'])) {
                        throw SocialRuleViolation::because('audience_required');
                    }
                }
                break;
            default:
                throw SocialRuleViolation::because('invalid_visibility');
        }

        return $audience;
    }

    /**
     * @param  array{visibility: string, territory_ids: list<int>, group_ids: list<int>, person_ids: list<int>, role_codes: list<string>}  $audience
     */
    private function syncAudience(Post $post, array $audience): void
    {
        $post->territories()->sync($audience['territory_ids']);
        $post->groups()->sync($audience['group_ids']);
        PostTarget::query()->where('post_id', $post->id)->delete();
        foreach ($audience['person_ids'] as $personId) {
            PostTarget::query()->create(['post_id' => $post->id, 'person_id' => $personId]);
        }
        foreach ($audience['role_codes'] as $code) {
            PostTarget::query()->create(['post_id' => $post->id, 'role_code' => $code]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function currentAudience(Post $post): array
    {
        return [
            'visibility' => $post->visibility,
            'territory_ids' => $post->territories()->pluck('territories.id')->all(),
            'group_ids' => $post->groups()->pluck('groups.id')->all(),
            'person_ids' => $post->targets()->whereNotNull('person_id')->pluck('person_id')->all(),
            'role_codes' => $post->targets()->whereNotNull('role_code')->pluck('role_code')->all(),
        ];
    }

    /**
     * @param  array{visibility: string, territory_ids: list<int>, group_ids: list<int>, person_ids: list<int>, role_codes: list<string>}  $audience
     * @return array<string, mixed>
     */
    private function audienceForJournal(array $audience): array
    {
        return array_filter([
            'territory_ids' => $audience['territory_ids'], 'group_ids' => $audience['group_ids'],
            'people' => count($audience['person_ids']), 'role_codes' => $audience['role_codes'],
        ]);
    }

    /**
     * @param  array{source: string, name: string, mime?: string|null}  $file
     */
    private function attach(Post $post, array $file, int $index): void
    {
        $extension = Str::lower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $path = 'social/posts/'.$post->id.'/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
        Storage::disk('local')->put($path, (string) file_get_contents($file['source']));
        $mime = $file['mime'] ?? (Storage::disk('local')->mimeType($path) ?: null);

        PostAttachment::query()->create([
            'post_id' => $post->id,
            'kind' => match (true) {
                str_starts_with((string) $mime, 'image/') => 'image',
                str_starts_with((string) $mime, 'video/') => 'video',
                default => 'file',
            },
            'path' => $path,
            'original_name' => mb_substr($file['name'], 0, 255),
            'mime' => $mime,
            'size' => Storage::disk('local')->size($path),
            'sort_order' => $index,
        ]);
    }

    private function ensureAuthor(User $actor, Post $post): void
    {
        if ($post->author_person_id !== $actor->person_id || $post->deleted_at !== null) {
            throw new AuthorizationException(__('access.denied'));
        }
    }

    private function ensureMayPin(User $actor, Post $post, string $scope, ?int $scopeId): void
    {
        $allowed = match ($scope) {
            PostPin::GLOBAL => $post->visibility === Post::PUBLIC && $this->hasOrganizationWide($actor, 'posts.pin'),
            PostPin::TERRITORY => $this->mayPinInTerritory($actor, $post, $scopeId),
            PostPin::GROUP => $this->mayPinInGroup($actor, $post, $scopeId),
            default => throw SocialRuleViolation::because('cannot_pin'),
        };
        if (! $allowed) {
            throw new AuthorizationException(__('access.denied'));
        }
    }

    private function mayPinInTerritory(User $actor, Post $post, ?int $territoryId): bool
    {
        $territory = $territoryId !== null ? Territory::query()->find($territoryId) : null;
        if ($territory === null || ! in_array($post->visibility, [Post::PUBLIC, Post::REGIONAL], true)) {
            return false;
        }

        // By the scope of the role (a regional moderator), organization-wide, or — for a head — inside the
        // territories of their own access (catalog §6 "Т").
        return $this->authorization->can($actor, 'posts.pin', $territory)
            || $this->hasOrganizationWide($actor, 'posts.pin')
            || ($this->authorization->can($actor, 'posts.pin') && $this->territories->covers($actor->person_id, $territory));
    }

    private function mayPinInGroup(User $actor, Post $post, ?int $groupId): bool
    {
        $group = $groupId !== null ? Group::query()->find($groupId) : null;

        return $group !== null && $post->groups()->whereKey($group->id)->exists() && $this->groups->canManage($actor, $group);
    }

    private function hasOrganizationWide(User $actor, string $code): bool
    {
        foreach ($this->authorization->grantsFor($actor, $code) as $grant) {
            if ($grant['scope'] === ScopeType::Organization && $grant['data'] === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tells the followers of the author — only those who can see the post.
     */
    private function announce(Post $post): void
    {
        if (in_array($post->visibility, [Post::PRIVATE], true)) {
            return;
        }
        $followers = User::query()->whereIn('person_id', DB::table('author_subscriptions')->where('author_person_id', $post->author_person_id)->select('follower_person_id'))->get();
        foreach ($this->visibility->among($post, $followers) as $follower) {
            $follower->notify(new SocialNotice(SocialNotice::NEW_POST, ['name' => $post->author->fullName()], $post->id));
        }
    }
}
