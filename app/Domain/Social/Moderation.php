<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Identity\Models\User;
use App\Domain\People\Models\Person;
use App\Domain\Social\Exceptions\SocialRuleViolation;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\ModerationAction;
use App\Domain\Social\Models\ModerationReport;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostRevision;
use App\Domain\Social\Models\Reaction;
use App\Domain\Social\Notifications\SocialNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moderation of the social network (ФО §6.4.4): the queue of complaints, hiding and restoring content with a
 * reason, warnings, a temporary mute that ends by itself.
 *
 * Who moderates what: a global moderator — everything; a regional one and a unit head — the content of the
 * people of their scope; a moderator of a group — the content of that group. Every action is journaled.
 */
final readonly class Moderation
{
    public function __construct(
        private AuthorizationService $authorization,
        private PostVisibility $visibility,
        private GroupAccess $groups,
        private EventJournal $journal,
    ) {}

    public function report(User $actor, Post|Comment $target, string $reasonCode, ?string $comment = null): ModerationReport
    {
        $this->authorization->authorize($actor, 'moderation.reports.create');
        if (! $this->visibility->canSee($actor, $this->postOf($target))) {
            throw new AuthorizationException(__('access.denied'));
        }
        if (! CatalogItem::query()->ofCatalog('report_reasons')->selectable()->where('code', $reasonCode)->exists()) {
            throw SocialRuleViolation::because('invalid_report_reason');
        }
        [$type, $id] = [$this->typeOf($target), $target->id];
        if (ModerationReport::query()->where('reportable_type', $type)->where('reportable_id', $id)
            ->where('reporter_person_id', $actor->person_id)->where('status', ModerationReport::OPEN)->exists()) {
            throw SocialRuleViolation::because('already_reported');
        }

        return DB::transaction(function () use ($actor, $type, $id, $reasonCode, $comment, $target): ModerationReport {
            $report = ModerationReport::query()->create([
                'reportable_type' => $type, 'reportable_id' => $id, 'reporter_person_id' => $actor->person_id,
                'reason_code' => $reasonCode, 'comment' => filled($comment) ? trim((string) $comment) : null,
            ]);
            $this->journal->record('social.report.created', $target, [], ['reason' => $reasonCode]);

            return $report;
        });
    }

    /**
     * Open complaints the moderator may handle.
     *
     * @return Builder<ModerationReport>
     */
    public function queueFor(User $moderator): Builder
    {
        $posts = $this->authorization->scopeQuery($moderator, 'moderation.queue.read', Post::query())->select('posts.id');
        $comments = $this->authorization->scopeQuery($moderator, 'moderation.queue.read', Comment::query())->select('post_comments.id');

        return ModerationReport::query()->where(fn (Builder $where) => $where
            ->where(fn (Builder $post) => $post->where('reportable_type', Reaction::POST)->whereIn('reportable_id', $posts))
            ->orWhere(fn (Builder $comment) => $comment->where('reportable_type', Reaction::COMMENT)->whereIn('reportable_id', $comments)));
    }

    /**
     * Does the user have a queue at all — by a system role or as a moderator of some group?
     */
    public function hasQueue(User $user): bool
    {
        return $this->authorization->can($user, 'moderation.queue.read')
            || GroupMember::query()->where('person_id', $user->person_id)->whereIn('role', GroupMember::MODERATORS)->exists();
    }

    public function mayModerate(User $moderator, Post|Comment $target, string $code = 'moderation.hide'): bool
    {
        return $this->authorization->can($moderator, $code, $target);
    }

    /**
     * Hides a post or a comment with a reason. Open complaints about it are upheld; the author is told.
     */
    public function hide(User $actor, Post|Comment $target, string $reason): void
    {
        // The author of a post moderates the comments under it (ФО §6.4.3).
        $ownThread = $target instanceof Comment && $target->post->author_person_id === $actor->person_id;
        if (! $ownThread) {
            $this->authorization->authorize($actor, 'moderation.hide', $target);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw SocialRuleViolation::because('reason_required');
        }
        if ($target->isHidden()) {
            return;
        }

        DB::transaction(function () use ($actor, $target, $reason): void {
            $target->forceFill(['hidden_at' => now(), 'hidden_by_user_id' => $actor->id, 'hidden_reason' => $reason])->save();
            $this->act(ModerationAction::HIDE, $actor, $target, $target->author_person_id, $reason);
            $this->resolveReports($actor, $target, ModerationReport::UPHELD);
            $this->journal->record('social.content.hidden', $target, [], ['reason' => $reason, 'author_person_id' => $target->author_person_id]);
        });
        $target->author->user?->notify(new SocialNotice(SocialNotice::HIDDEN, ['reason' => $reason], $this->postOf($target)->id));
    }

    public function restore(User $actor, Post|Comment $target): void
    {
        $this->authorization->authorize($actor, 'moderation.hide', $target);
        if (! $target->isHidden()) {
            return;
        }

        DB::transaction(function () use ($actor, $target): void {
            $target->forceFill(['hidden_at' => null, 'hidden_by_user_id' => null, 'hidden_reason' => null])->save();
            $this->act(ModerationAction::RESTORE, $actor, $target, $target->author_person_id, null);
            $this->journal->record('social.content.restored', $target);
        });
    }

    public function dismiss(User $actor, ModerationReport $report): void
    {
        $target = $this->targetOf($report);
        $this->authorization->authorize($actor, 'moderation.queue.read', $target);
        if ($report->status !== ModerationReport::OPEN) {
            throw SocialRuleViolation::because('report_resolved');
        }

        DB::transaction(function () use ($actor, $report, $target): void {
            $report->update(['status' => ModerationReport::DISMISSED, 'resolved_by_user_id' => $actor->id, 'resolved_at' => now()]);
            $this->journal->record('social.report.dismissed', $target, [], ['report_id' => $report->id]);
        });
    }

    public function warn(User $actor, Person $person, string $reason): ModerationAction
    {
        $this->authorization->authorize($actor, 'moderation.warn', $person);
        $reason = trim($reason);
        if ($reason === '') {
            throw SocialRuleViolation::because('reason_required');
        }

        $action = DB::transaction(function () use ($actor, $person, $reason): ModerationAction {
            $action = $this->act(ModerationAction::WARN, $actor, null, $person->id, $reason);
            $this->journal->record('social.user.warned', $person, [], ['reason' => $reason]);

            return $action;
        });
        $person->user?->notify(new SocialNotice(SocialNotice::WARNING, ['reason' => $reason]));

        return $action;
    }

    /**
     * A temporary mute: until the given moment the person cannot publish posts or comments. It ends by itself.
     */
    public function mute(User $actor, Person $person, Carbon $until, string $reason): ModerationAction
    {
        $this->authorization->authorize($actor, 'moderation.mute', $person);
        $reason = trim($reason);
        if ($reason === '') {
            throw SocialRuleViolation::because('reason_required');
        }
        if (! $until->isFuture()) {
            throw SocialRuleViolation::because('mute_in_past');
        }

        $action = DB::transaction(function () use ($actor, $person, $until, $reason): ModerationAction {
            // One mute at a time: a new one replaces the current one.
            $this->activeMutes($person->id)->update(['lifted_at' => now()]);
            $action = $this->act(ModerationAction::MUTE, $actor, null, $person->id, $reason, $until);
            $this->journal->record('social.user.muted', $person, [], ['reason' => $reason, 'until' => $until->toIso8601String()]);

            return $action;
        });
        $person->user?->notify(new SocialNotice(SocialNotice::MUTED, ['reason' => $reason, 'until' => $until->isoFormat('LLL')]));

        return $action;
    }

    public function unmute(User $actor, Person $person): void
    {
        $this->authorization->authorize($actor, 'moderation.mute', $person);

        DB::transaction(function () use ($actor, $person): void {
            if ($this->activeMutes($person->id)->update(['lifted_at' => now()]) > 0) {
                $this->act(ModerationAction::UNMUTE, $actor, null, $person->id, null);
                $this->journal->record('social.user.unmuted', $person);
            }
        });
    }

    public function mutedUntil(int $personId): ?Carbon
    {
        $until = $this->activeMutes($personId)->max('expires_at');

        return $until !== null ? Carbon::parse($until) : null;
    }

    public function ensureNotMuted(User $actor): void
    {
        $until = $this->mutedUntil($actor->person_id);
        if ($until !== null) {
            throw SocialRuleViolation::because('muted', ['until' => $until->isoFormat('LLL')]);
        }
    }

    /**
     * Mutes whose time has come are closed and journaled (scheduler). The mute itself stops working by the clock,
     * whether or not this has run yet.
     */
    public function expireMutes(): int
    {
        $expired = ModerationAction::query()->where('action', ModerationAction::MUTE)->whereNull('lifted_at')
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->get();
        foreach ($expired as $mute) {
            DB::transaction(function () use ($mute): void {
                $mute->update(['lifted_at' => $mute->expires_at]);
                $person = Person::query()->find($mute->person_id);
                if ($person !== null) {
                    $this->journal->record('social.user.mute_expired', $person);
                }
            });
        }

        return $expired->count();
    }

    /**
     * The edit history of a post: its author reads it freely; a moderator of the post — with a journal entry.
     *
     * @return Collection<int, PostRevision>
     */
    public function revisions(User $viewer, Post $post): Collection
    {
        if ($post->author_person_id !== $viewer->person_id) {
            $this->authorization->authorize($viewer, 'moderation.revisions.read', $post);
            $this->journal->record('social.revisions.viewed', $post);
        }

        return $post->revisions()->get();
    }

    /**
     * Warnings, mutes, hidings and restorations concerning the people the moderator reaches.
     *
     * @return Builder<ModerationAction>
     */
    public function actionsFor(User $moderator): Builder
    {
        return ModerationAction::query()->whereIn('person_id',
            $this->authorization->scopeQuery($moderator, 'moderation.queue.read', Person::query())->select('people.id'));
    }

    public function targetOf(ModerationReport $report): Post|Comment
    {
        return $report->reportable_type === Reaction::POST
            ? Post::query()->findOrFail($report->reportable_id)
            : Comment::query()->findOrFail($report->reportable_id);
    }

    /**
     * Is the post published in a group this user moderates? (The "Св" of the moderation codes.)
     */
    public function moderatesGroupOf(User $user, Post $post): bool
    {
        if ($post->visibility !== Post::GROUP) {
            return false;
        }
        foreach ($post->groups as $group) {
            /** @var Group $group */
            if ($this->groups->canModerate($user, $group)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Builder<ModerationAction>
     */
    private function activeMutes(int $personId): Builder
    {
        return ModerationAction::query()->where('action', ModerationAction::MUTE)->where('person_id', $personId)
            ->whereNull('lifted_at')->where('expires_at', '>', now());
    }

    private function act(string $action, User $moderator, Post|Comment|null $target, ?int $personId, ?string $reason, ?Carbon $expiresAt = null): ModerationAction
    {
        return ModerationAction::query()->create([
            'action' => $action,
            'target_type' => $target !== null ? $this->typeOf($target) : null,
            'target_id' => $target?->id,
            'person_id' => $personId,
            'moderator_user_id' => $moderator->id,
            'reason' => $reason,
            'expires_at' => $expiresAt,
        ]);
    }

    private function resolveReports(User $actor, Post|Comment $target, string $status): void
    {
        ModerationReport::query()->where('reportable_type', $this->typeOf($target))->where('reportable_id', $target->id)
            ->where('status', ModerationReport::OPEN)
            ->update(['status' => $status, 'resolved_by_user_id' => $actor->id, 'resolved_at' => now()]);
    }

    private function typeOf(Post|Comment $target): string
    {
        return $target instanceof Post ? Reaction::POST : Reaction::COMMENT;
    }

    private function postOf(Post|Comment $target): Post
    {
        return $target instanceof Post ? $target : $target->post;
    }
}
