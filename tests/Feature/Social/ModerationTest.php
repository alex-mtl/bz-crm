<?php

use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Social\Actions\ManageComments;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Exceptions\SocialRuleViolation;
use App\Domain\Social\Models\ModerationAction;
use App\Domain\Social\Models\ModerationReport;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Moderation;
use App\Domain\Social\Notifications\SocialNotice;
use App\Domain\Social\PostVisibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SocialFixture;

/*
 * ФО §6.4.4 — moderation: complaints, the queue, hiding with a reason, restoring, warnings, a temporary mute.
 * A moderator reaches the content of the people of their scope; a moderator of a group — the content of the group.
 */

beforeEach(function () {
    $this->social = SocialFixture::build();
    $this->org = $this->social->org;
    $this->moderation = app(Moderation::class);
    $this->posts = app(ManagePosts::class);
    $this->comments = app(ManageComments::class);
    $this->queue = fn ($user): array => $this->moderation->queueFor($user)->where('status', ModerationReport::OPEN)->pluck('id')->all();
});

it('takes a complaint about what the reporter sees, once', function () {
    $o = $this->org;
    $post = $this->social->centruPost();
    $comment = $this->comments->add($o->a2, $post, 'Comentariu');

    $report = $this->moderation->report($o->a2, $post, 'spam', ' Reclamă ');
    $this->moderation->report($o->headA, $comment, 'insult');

    expect($report)->status->toBe(ModerationReport::OPEN)->comment->toBe('Reclamă')
        ->and(journalCount('social.report.created'))->toBe(2)
        ->and(fn () => $this->moderation->report($o->a2, $post, 'insult'))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->moderation->report($o->regionHead, $post, 'because'))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->moderation->report($o->b1, $post, 'spam'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->moderation->report($o->b1, $comment, 'spam'))->toThrow(AuthorizationException::class);
});

it('shows a moderator the complaints of their scope only', function () {
    $o = $this->org;
    $ofA = $this->moderation->report($o->a2, $this->social->centruPost(), 'spam');
    $baltiPost = $this->social->post($o->balti1, 'Bălți', ['visibility' => Post::REGIONAL, 'territory_ids' => [$o->baltiTerritory->id]]);
    $ofBalti = $this->moderation->report($o->baltiHead, $baltiPost, 'off_topic');

    expect(($this->queue)($o->headA))->toBe([$ofA->id])
        ->and(($this->queue)($o->headB))->toBe([])
        ->and(($this->queue)($this->social->moderator))->toBe([$ofA->id])
        ->and(($this->queue)($o->baltiHead))->toBe([$ofBalti->id])
        ->and(($this->queue)($o->admin))->toBe([$ofA->id, $ofBalti->id])
        ->and(($this->queue)($o->a1))->toBe([]);
});

it('hides content with a reason, tells the author and upholds the complaints', function () {
    Notification::fake();
    $o = $this->org;
    $post = $this->social->centruPost();
    $report = $this->moderation->report($o->a2, $post, 'false_info');
    $sees = fn ($user): bool => app(PostVisibility::class)->canSee($user, $post);

    expect(fn () => $this->moderation->hide($o->headB, $post, 'Motiv'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->moderation->hide($o->a2, $post, 'Motiv'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->moderation->hide($o->headA, $post, '  '))->toThrow(SocialRuleViolation::class);

    $this->moderation->hide($o->headA, $post, 'Informație neverificată');

    expect($post->fresh())->hidden_reason->toBe('Informație neverificată')->hidden_by_user_id->toBe($o->headA->id)
        ->and($report->fresh())->status->toBe(ModerationReport::UPHELD)->resolved_by_user_id->toBe($o->headA->id)
        ->and($sees($o->a2))->toBeFalse()
        ->and($sees($o->headA))->toBeFalse()
        // The author still sees the post — with the reason it was hidden for.
        ->and($sees($o->a1))->toBeTrue()
        ->and(journalCount('social.content.hidden'))->toBe(1)
        ->and(fn () => $this->comments->add($o->a2, $post->fresh(), 'X'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->moderation->restore($o->headB, $post->fresh()))->toThrow(AuthorizationException::class);
    Notification::assertSentTo($o->a1, SocialNotice::class, fn (SocialNotice $n) => $n->kind === SocialNotice::HIDDEN);

    $this->moderation->restore($this->social->moderator, $post->fresh());

    expect($post->fresh()->hidden_at)->toBeNull()
        ->and($sees($o->a2))->toBeTrue()
        ->and(ModerationAction::query()->pluck('action')->all())->toBe([ModerationAction::HIDE, ModerationAction::RESTORE])
        ->and(journalCount('social.content.restored'))->toBe(1);
});

it('dismisses a complaint without touching the content', function () {
    $o = $this->org;
    $post = $this->social->centruPost();
    $report = $this->moderation->report($o->a2, $post, 'off_topic');

    expect(fn () => $this->moderation->dismiss($o->headB, $report))->toThrow(AuthorizationException::class);

    $this->moderation->dismiss($o->headA, $report);

    expect($report->fresh()->status)->toBe(ModerationReport::DISMISSED)
        ->and($post->fresh()->hidden_at)->toBeNull()
        ->and(($this->queue)($o->headA))->toBe([])
        ->and(fn () => $this->moderation->dismiss($o->headA, $report->fresh()))->toThrow(SocialRuleViolation::class)
        ->and(journalCount('social.report.dismissed'))->toBe(1);
});

it('lets the author of a post hide the comments under it', function () {
    $o = $this->org;
    $post = $this->social->centruPost();
    $comment = $this->comments->add($o->a2, $post, 'Nu sunt de acord');
    $ofHead = $this->comments->add($o->headA, $post, 'Bine');

    expect(fn () => $this->moderation->hide($o->a2, $ofHead, 'Motiv'))->toThrow(AuthorizationException::class);

    $this->moderation->hide($o->a1, $comment, 'În afara subiectului');

    expect($comment->fresh()->isHidden())->toBeTrue()
        ->and($this->comments->thread($o->a2, $post)->first()->isHidden())->toBeTrue()
        ->and(fn () => $this->comments->react($o->headA, $comment->fresh(), 'like'))->toThrow(AuthorizationException::class);
});

it('lets a moderator of a group moderate the content of that group without a system role', function () {
    $o = $this->org;
    $group = $this->social->group($o->b1, 'Voluntari', Group::CLOSED, [$o->a1, $o->balti1]);
    app(ManageGroups::class)->setRole($o->b1, $group, $o->balti1->person, GroupMember::MODERATOR);
    $inGroup = $this->social->post($o->a1, 'În grup', ['visibility' => Post::GROUP, 'group_ids' => [$group->id]]);
    $comment = $this->comments->add($o->a1, $inGroup, 'Comentariu');
    $regional = $this->social->centruPost();
    $report = $this->moderation->report($o->b1, $inGroup, 'spam');

    expect(($this->queue)($o->balti1))->toBe([$report->id])
        ->and($this->moderation->mayModerate($o->balti1, $inGroup))->toBeTrue()
        ->and($this->moderation->mayModerate($o->balti1, $comment))->toBeTrue()
        ->and($this->moderation->mayModerate($o->balti1, $regional))->toBeFalse()
        // A plain member of the group moderates nothing.
        ->and($this->moderation->mayModerate($o->a1, $inGroup))->toBeFalse()
        // The head of the author's branch reaches the author's content, but does not see the group.
        ->and($this->moderation->mayModerate($o->headB, $inGroup))->toBeFalse();

    $this->moderation->hide($o->balti1, $inGroup, 'Spam');
    $this->moderation->hide($o->b1, $comment, 'Spam');

    expect($inGroup->fresh()->isHidden())->toBeTrue()
        ->and($comment->fresh()->isHidden())->toBeTrue()
        ->and(fn () => $this->moderation->warn($o->balti1, $o->a1->person, 'Spam'))->toThrow(AuthorizationException::class);
});

it('warns and mutes within the scope of the moderator; the mute ends by itself', function () {
    Notification::fake();
    $o = $this->org;
    $moderator = $this->social->moderator;
    $post = $this->social->centruPost();
    $draft = $this->social->post($o->a1, 'Ciornă', ['intent' => 'draft']);
    $scheduled = $this->social->post($o->a1, 'Programat', ['intent' => 'schedule', 'publish_at' => now()->addMinutes(30)]);

    $this->moderation->warn($moderator, $o->a1->person, 'Limbaj nepotrivit');
    $this->moderation->mute($moderator, $o->a1->person, now()->addHours(2), 'Spam repetat');

    expect($this->moderation->mutedUntil($o->a1->person_id))->not->toBeNull()
        ->and(fn () => $this->social->centruPost())->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->comments->add($o->a1, $post, 'X'))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->posts->publish($o->a1, $draft))->toThrow(SocialRuleViolation::class)
        // Reading, reacting and writing drafts stay possible.
        ->and($this->comments->react($o->a1, $post, 'like'))->not->toBeNull()
        ->and($this->social->post($o->a1, 'Altă ciornă', ['intent' => 'draft'])->status)->toBe(Post::DRAFT)
        // Out of the moderator's region; a unit head hides content but does not punish people.
        ->and(fn () => $this->moderation->mute($moderator, $o->balti1->person, now()->addHour(), 'X'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->moderation->warn($moderator, $o->balti1->person, 'X'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->moderation->mute($o->headA, $o->a1->person, now()->addHour(), 'X'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->moderation->mute($moderator, $o->a2->person, now()->subHour(), 'X'))->toThrow(SocialRuleViolation::class)
        ->and(fn () => $this->moderation->mute($moderator, $o->a2->person, now()->addHour(), ' '))->toThrow(SocialRuleViolation::class)
        ->and(journalCount('social.user.warned'))->toBe(1)
        ->and(journalCount('social.user.muted'))->toBe(1);
    Notification::assertSentTo($o->a1, SocialNotice::class, fn (SocialNotice $n) => $n->kind === SocialNotice::WARNING);
    Notification::assertSentTo($o->a1, SocialNotice::class, fn (SocialNotice $n) => $n->kind === SocialNotice::MUTED);

    // The scheduled post of a muted author waits for the end of the mute.
    $this->travel(1)->hours();
    expect($this->posts->publishDue())->toBe(0);

    $this->travel(2)->hours();

    expect($this->moderation->mutedUntil($o->a1->person_id))->toBeNull()
        ->and($this->social->centruPost('După mut')->status)->toBe(Post::PUBLISHED)
        ->and($this->posts->publishDue())->toBe(1)
        ->and($scheduled->fresh()->status)->toBe(Post::PUBLISHED)
        ->and($this->moderation->expireMutes())->toBe(1)
        ->and($this->moderation->expireMutes())->toBe(0)
        ->and(journalCount('social.user.mute_expired'))->toBe(1);
});

it('lifts a mute before its time', function () {
    $o = $this->org;
    $this->moderation->mute($this->social->moderator, $o->a1->person, now()->addDay(), 'Spam');
    $this->moderation->mute($this->social->moderator, $o->a1->person, now()->addDays(2), 'Spam din nou');

    expect(ModerationAction::query()->where('action', ModerationAction::MUTE)->whereNull('lifted_at')->count())->toBe(1)
        ->and(fn () => $this->moderation->unmute($o->headA, $o->a1->person))->toThrow(AuthorizationException::class);

    $this->moderation->unmute($this->social->moderator, $o->a1->person);

    expect($this->moderation->mutedUntil($o->a1->person_id))->toBeNull()
        ->and($this->social->centruPost()->status)->toBe(Post::PUBLISHED)
        ->and(journalCount('social.user.unmuted'))->toBe(1);
});

it('runs the scheduler tick', function () {
    $o = $this->org;
    $this->social->post($o->a1, 'Programat', ['intent' => 'schedule', 'publish_at' => now()->addMinutes(5)]);
    $this->travel(10)->minutes();

    $this->artisan('social:tick')->expectsOutputToContain('Published: 1')->assertSuccessful();
});
