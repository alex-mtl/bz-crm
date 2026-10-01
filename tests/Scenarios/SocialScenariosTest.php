<?php

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Groups\Actions\ManageGroups;
use App\Domain\Groups\Exceptions\GroupRuleViolation;
use App\Domain\Groups\GroupAccess;
use App\Domain\Groups\Models\Group;
use App\Domain\Groups\Models\GroupInvitation;
use App\Domain\Groups\Models\GroupJoinRequest;
use App\Domain\Groups\Models\GroupMember;
use App\Domain\Social\Actions\ManageComments;
use App\Domain\Social\Actions\ManagePosts;
use App\Domain\Social\Exceptions\SocialRuleViolation;
use App\Domain\Social\Feed;
use App\Domain\Social\Models\Comment;
use App\Domain\Social\Models\ModerationAction;
use App\Domain\Social\Models\ModerationReport;
use App\Domain\Social\Models\Post;
use App\Domain\Social\Models\PostPin;
use App\Domain\Social\Models\Reaction;
use App\Domain\Social\Moderation;
use App\Domain\Social\Notifications\SocialNotice;
use App\Domain\Social\PostVisibility;
use App\Filament\Pages\SocialFeed;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Moderation\Pages\ListReports;
use Database\Seeders\Demo\Personas;
use Database\Seeders\Demo\SocialDemoSeeder as Social;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * IMPLEMENTATION-PLAN 4.3 — the social network and groups on the demo world (docs/demo/README.md § Соцсеть).
 */

/**
 * Ids of the posts in the feed of a persona.
 *
 * @param  array<string, mixed>  $filters
 * @return list<int>
 */
function feedOf(string $persona, string $mode = Feed::ALL, array $filters = []): array
{
    app(AuthorizationService::class)->forget();

    return app(Feed::class)->query(Personas::user($persona), $mode, $filters)->pluck('posts.id')->all();
}

function seesPost(string $persona, string $body): bool
{
    app(AuthorizationService::class)->forget();

    return app(PostVisibility::class)->canSee(Personas::user($persona), Social::post($body));
}

it('hides a regional post of Chișinău from an employee of Bălți — in the feed, the search, the API and the notifications', function () {
    $tatiana = Personas::user('balti_employee_1');
    $chisinau = Social::post(Social::CHISINAU);
    $centru = Social::post(Social::CENTRU_POLL);

    expect(feedOf('balti_employee_1'))->toContain(Social::post(Social::WELCOME)->id, Social::post(Social::BALTI)->id)
        ->not->toContain($chisinau->id, $centru->id, Social::post(Social::BOTANICA)->id)
        ->and(feedOf('balti_employee_1', Feed::ALL, ['search' => 'Chișinău']))->toBe([])
        ->and(feedOf('balti_employee_1', Feed::ALL, ['territory_id' => Personas::territory('chisinau')->id]))->toBe([])
        ->and(feedOf('branch_a_employee_1'))->toContain($chisinau->id, $centru->id);

    $this->actingAs($tatiana);
    $this->get('/admin/feed')->assertOk()->assertSee(Social::BALTI)->assertDontSee(Social::CHISINAU);
    $this->get('/admin/feed?search=Chișinău')->assertOk()->assertDontSee(Social::CHISINAU);
    $this->get('/admin/feed?post='.$chisinau->id)->assertOk()->assertDontSee(Social::CHISINAU);
    $ids = $this->getJson('/api/v1/feed?per_page=50')->assertOk()->json('data.*.id');
    expect($ids)->toContain(Social::post(Social::BALTI)->id)->not->toContain($chisinau->id, $centru->id);
    $this->getJson('/api/v1/feed?search=activului')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/posts/'.$chisinau->id)->assertNotFound();

    // She follows Ion, yet was told nothing about his post for the sector Centru — unlike Maria.
    $told = fn (string $persona): array => Personas::user($persona)->notifications()->where('type', SocialNotice::class)->get()
        ->map(fn ($notification) => $notification->data['post_id'] ?? null)->filter()->values()->all();
    expect($told('balti_employee_1'))->not->toContain($centru->id)
        ->and($told('branch_a_employee_2'))->toContain($centru->id);

    Notification::fake();
    app(ManagePosts::class)->create(Personas::user('branch_a_employee_1'), [
        'body' => 'Un anunț nou pentru Centru', 'visibility' => Post::REGIONAL, 'territory_ids' => [Personas::territory('chisinau/sectorul-centru')->id],
    ]);
    Notification::assertSentTo(Personas::user('branch_a_employee_2'), SocialNotice::class);
    Notification::assertNotSentTo($tatiana, SocialNotice::class);
});

it('shows a regional post in both directions: the region to its sectors, a sector to its region', function () {
    expect(seesPost('branch_b_employee_1', Social::CHISINAU))->toBeTrue()          // a sector reads the post for the whole region
        ->and(seesPost('chisinau_head', Social::CENTRU_POLL))->toBeTrue()          // the region head reads the posts of its sectors
        ->and(seesPost('org_head', Social::BOTANICA))->toBeTrue()
        ->and(seesPost('branch_b_employee_1', Social::CENTRU_POLL))->toBeFalse()   // the neighbouring sector does not
        ->and(seesPost('north_south_employee', Social::BALTI))->toBeTrue()         // Nord–Sud covers Bălți and Cahul
        ->and(seesPost('north_south_employee', Social::CAHUL))->toBeTrue()
        ->and(seesPost('balti_employee_2', Social::CAHUL))->toBeFalse()
        ->and(seesPost('central_employee', Social::CHISINAU))->toBeFalse();        // no territories — no regional posts
});

it('keeps a secret group invisible to non-members everywhere, counters included', function () {
    $secret = Social::group(Social::GROUP_SECRET);
    $post = Social::post(Social::IN_SECRET_GROUP);
    $access = app(GroupAccess::class);
    $visible = fn (string $persona): array => $access->visible(Personas::user($persona))->orderBy('name')->pluck('name')->all();

    expect($visible('balti_employee_1'))->toBe([Social::GROUP_CLOSED, Social::GROUP_OPEN])
        ->and($visible('branch_a_employee_2'))->toBe([Social::GROUP_ARCHIVED, Social::GROUP_CLOSED, Social::GROUP_OPEN])
        ->and($visible('org_head'))->toBe([Social::GROUP_CLOSED, Social::GROUP_SECRET, Social::GROUP_OPEN])
        // Not the super admin either: a secret group exists for its members only.
        ->and($access->canSee(Personas::user('super_admin'), $secret))->toBeFalse()
        ->and($access->visible(Personas::user('branch_a_employee_2'))->count())->toBe(3)
        ->and(feedOf('branch_a_employee_2', Feed::ALL, ['group_id' => $secret->id]))->toBe([])
        ->and(feedOf('branch_a_employee_2', Feed::ALL, ['search' => 'candidaților']))->toBe([])
        ->and(seesPost('branch_a_employee_2', Social::IN_SECRET_GROUP))->toBeFalse()
        ->and(seesPost('branch_b_head', Social::IN_SECRET_GROUP))->toBeTrue()
        ->and(fn () => app(ManageGroups::class)->join(Personas::user('branch_a_employee_2'), $secret))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManagePosts::class)->create(Personas::user('branch_a_employee_2'), ['body' => 'X', 'visibility' => Post::GROUP, 'group_ids' => [$secret->id]]))
        ->toThrow(AuthorizationException::class);

    $this->actingAs(Personas::user('branch_a_employee_2'));
    Livewire::test(ListGroups::class)->assertCanNotSeeTableRecords([$secret])->assertCountTableRecords(3)
        ->searchTable('alegeri')->assertCountTableRecords(0);
    $this->get('/admin/groups/'.$secret->id)->assertNotFound();
    $this->get('/admin/feed?group='.$secret->id)->assertOk()->assertDontSee(Social::IN_SECRET_GROUP);
    $this->getJson('/api/v1/posts/'.$post->id)->assertNotFound();
    expect(Livewire::test(SocialFeed::class)->instance()->getMyGroupsProperty())->not->toHaveKey($secret->id);
});

it('shows a secret group to an invited person only as an invitation until they accept', function () {
    $ana = Personas::user('branch_a_head');
    $secret = Social::group(Social::GROUP_SECRET);
    $groups = app(ManageGroups::class);
    $access = app(GroupAccess::class);

    expect($groups->invitationsFor($ana)->pluck('group_id')->all())->toBe([$secret->id])
        ->and($access->canSee($ana, $secret))->toBeFalse();

    $groups->answerInvitation($ana, $groups->invitationsFor($ana)->sole(), true);
    $access->forget();

    expect($access->canSee($ana, $secret))->toBeTrue()
        ->and(seesPost('branch_a_head', Social::IN_SECRET_GROUP))->toBeTrue()
        // Pavel came by the link; it still has uses left.
        ->and($access->isMember($secret, Personas::user('branch_b_head')->person_id))->toBeTrue()
        ->and(GroupInvitation::query()->where('group_id', $secret->id)->whereNotNull('token_hash')->sole())->uses->toBe(1)->max_uses->toBe(3);
});

it('has groups of all three types with roles, a request waiting, a rejected one and a bulk invitation', function () {
    $closed = Social::group(Social::GROUP_CLOSED);
    $open = Social::group(Social::GROUP_OPEN);
    $access = app(GroupAccess::class);
    $role = fn (Group $group, string $persona): ?string => $access->roleOf($group, Personas::user($persona)->person_id);

    expect(Group::query()->pluck('type')->unique()->sort()->values()->all())->toBe([Group::CLOSED, Group::OPEN, Group::SECRET])
        ->and($role($open, 'branch_a_employee_2'))->toBe(GroupMember::OWNER)
        ->and($role($open, 'branch_a_employee_1'))->toBe(GroupMember::MODERATOR)
        ->and($role($open, 'volunteer'))->toBe(GroupMember::MEMBER)
        ->and($role($closed, 'branch_a_head'))->toBe(GroupMember::OWNER)
        ->and($role($closed, 'branch_a_employee_2'))->toBe(GroupMember::ADMIN)
        ->and($closed->org_unit_id)->toBe(Personas::unit('branch_a')->id)
        // The bulk invitation reached the active accounts of branch A only: Sergiu and Marin have not answered, Radu declined.
        ->and(GroupInvitation::query()->where('group_id', $closed->id)->where('status', GroupInvitation::PENDING)->pluck('person_id')->all())
        ->toEqualCanonicalizing([Personas::user('branch_a_employee_3')->person_id, Personas::user('google_user')->person_id])
        ->and(GroupInvitation::query()->where('group_id', $closed->id)->where('person_id', Personas::user('volunteer')->person_id)->sole()->status)->toBe(GroupInvitation::DECLINED)
        ->and(GroupInvitation::query()->where('group_id', $closed->id)->where('person_id', Personas::user('deactivated')->person_id)->exists())->toBeFalse()
        ->and(GroupJoinRequest::query()->where('group_id', $closed->id)->pluck('status')->all())->toEqualCanonicalizing([GroupJoinRequest::REJECTED, GroupJoinRequest::PENDING]);

    // Maria, an admin of the group, accepts Olga; Ion, a plain member, cannot.
    $request = GroupJoinRequest::query()->where('group_id', $closed->id)->where('status', GroupJoinRequest::PENDING)->sole();
    expect(fn () => app(ManageGroups::class)->decideRequest(Personas::user('branch_a_employee_1'), $request, true))->toThrow(AuthorizationException::class);
    app(ManageGroups::class)->decideRequest(Personas::user('branch_a_employee_2'), $request, true);
    $access->forget();

    expect($role($closed, 'branch_b_employee_1'))->toBe(GroupMember::MEMBER)
        ->and(seesPost('branch_b_employee_1', Social::IN_CLOSED_GROUP))->toBeTrue()
        ->and(seesPost('branch_b_employee_2', Social::IN_CLOSED_GROUP))->toBeFalse()
        ->and(fn () => app(ManageGroups::class)->leave(Personas::user('branch_a_head'), $closed))->toThrow(GroupRuleViolation::class);
});

it('lets the head of branch A moderate the posts of her staff and not those of branch B', function () {
    $ana = Personas::user('branch_a_head');
    $pavel = Personas::user('branch_b_head');
    $moderation = app(Moderation::class);
    $rumour = Social::post(Social::REPORTED);
    $advert = Social::post(Social::HIDDEN);
    $open = fn (string $persona): array => $moderation->queueFor(Personas::user($persona))->where('status', ModerationReport::OPEN)->pluck('reportable_id')->all();

    expect($open('branch_a_head'))->toBe([$rumour->id])
        ->and($open('branch_b_head'))->toBe([])
        ->and($open('chisinau_head'))->toBe([$rumour->id])
        ->and($open('moderator'))->toBe([$rumour->id])
        ->and($open('balti_head'))->toBe([])
        ->and($open('branch_a_employee_1'))->toBe([])
        ->and(fn () => $moderation->hide($pavel, $rumour, 'Zvon'))->toThrow(AuthorizationException::class)
        ->and(fn () => $moderation->restore($ana, $advert))->toThrow(AuthorizationException::class)
        ->and(fn () => $moderation->hide(Personas::user('branch_a_employee_2'), $rumour, 'Zvon'))->toThrow(AuthorizationException::class);

    $this->actingAs($pavel)->get('/admin/moderation')->assertOk()->assertDontSee(Social::REPORTED);
    $this->flushSession();
    $this->actingAs($ana);
    Livewire::test(ListReports::class)->assertCountTableRecords(1)
        ->callTableAction('hide', ModerationReport::query()->where('status', ModerationReport::OPEN)->sole(), data: ['reason' => 'Informație neconfirmată']);

    expect($rumour->fresh()->isHidden())->toBeTrue()
        ->and(ModerationReport::query()->where('status', ModerationReport::OPEN)->count())->toBe(0)
        ->and(seesPost('branch_a_employee_2', Social::REPORTED))->toBeFalse()
        ->and(seesPost('branch_a_employee_3', Social::REPORTED))->toBeTrue()
        ->and(JournalEntry::query()->where('event_type', 'social.content.hidden')->latest('id')->first()->actor_user_id)->toBe($ana->id);
});

it('shows a hidden post only to its author, with the reason, and lets the right head restore it', function () {
    $advert = Social::post(Social::HIDDEN);

    expect($advert)->hidden_reason->not->toBeNull()
        ->and($advert->hidden_by_user_id)->toBe(Personas::user('branch_b_head')->id)
        ->and(seesPost('branch_b_employee_2', Social::HIDDEN))->toBeTrue()
        ->and(seesPost('branch_b_employee_1', Social::HIDDEN))->toBeFalse()
        ->and(seesPost('branch_b_head', Social::HIDDEN))->toBeFalse()
        ->and(ModerationReport::query()->where('reportable_type', Reaction::POST)->where('reportable_id', $advert->id)->sole()->status)->toBe(ModerationReport::UPHELD);

    $this->actingAs(Personas::user('branch_b_employee_2'))->get('/admin/feed?mode=mine')->assertOk()->assertSee($advert->hidden_reason);

    app(Moderation::class)->restore(Personas::user('branch_b_head'), $advert);

    expect(seesPost('branch_b_employee_1', Social::HIDDEN))->toBeTrue();
});

it('lets a moderator of a group moderate the group and nothing else', function () {
    $ion = Personas::user('branch_a_employee_1');          // moderator of the open group, no moderation role
    $moderation = app(Moderation::class);

    expect($moderation->mayModerate($ion, Social::post(Social::IN_OPEN_GROUP)))->toBeTrue()
        ->and($moderation->mayModerate($ion, Social::post(Social::BOTANICA)))->toBeFalse()
        ->and($moderation->mayModerate($ion, Social::post(Social::IN_CLOSED_GROUP)))->toBeFalse()
        ->and($moderation->mayModerate(Personas::user('volunteer'), Social::post(Social::IN_OPEN_GROUP)))->toBeFalse()
        ->and($moderation->hasQueue($ion))->toBeTrue()
        ->and($moderation->hasQueue(Personas::user('volunteer')))->toBeFalse()
        ->and(fn () => $moderation->warn($ion, Personas::user('volunteer')->person, 'X'))->toThrow(AuthorizationException::class);
});

it('does not let a muted user publish until the mute ends', function () {
    $dan = Personas::user('branch_b_employee_2');
    $moderation = app(Moderation::class);
    $audience = ['visibility' => Post::REGIONAL, 'territory_ids' => [Personas::territory('chisinau/sectorul-botanica')->id]];

    expect($moderation->mutedUntil($dan->person_id))->not->toBeNull()
        ->and(fn () => app(ManagePosts::class)->create($dan, ['body' => 'Încă o reclamă', ...$audience]))->toThrow(SocialRuleViolation::class)
        ->and(fn () => app(ManageComments::class)->add($dan, Social::post(Social::CHISINAU), 'Comentariu'))->toThrow(SocialRuleViolation::class)
        ->and(app(ManageComments::class)->react($dan, Social::post(Social::WELCOME), 'like'))->not->toBeNull()
        ->and(ModerationAction::query()->where('person_id', $dan->person_id)->pluck('action')->all())->toContain(ModerationAction::WARN, ModerationAction::MUTE);

    $this->actingAs($dan)->get('/admin/feed')->assertOk()->assertSee(__('social.errors.muted', ['until' => $moderation->mutedUntil($dan->person_id)->isoFormat('LLL')]));

    $this->travel(3)->days();

    expect($moderation->mutedUntil($dan->person_id))->toBeNull()
        ->and(app(ManagePosts::class)->create($dan, ['body' => 'Mulțumesc, am înțeles', ...$audience])->status)->toBe(Post::PUBLISHED);
});

it('has a mute that ended by itself: the person publishes again, the end is journaled', function () {
    $vasile = Personas::user('balti_employee_2');
    $mute = ModerationAction::query()->where('action', ModerationAction::MUTE)->where('person_id', $vasile->person_id)->sole();

    expect($mute->lifted_at)->not->toBeNull()
        ->and($mute->lifted_at->equalTo($mute->expires_at))->toBeTrue()
        ->and(app(Moderation::class)->mutedUntil($vasile->person_id))->toBeNull()
        ->and(Social::post(Social::AFTER_MUTE)->published_at->greaterThan($mute->expires_at))->toBeTrue()
        ->and(JournalEntry::query()->where('event_type', 'social.user.mute_expired')->count())->toBe(1);
});

it('gives comments and reactions the visibility of their post', function () {
    $post = Social::post(Social::CENTRU_POLL);
    $comments = app(ManageComments::class);
    $olga = Personas::user('branch_b_employee_1');
    $thread = $comments->thread(Personas::user('branch_a_employee_2'), $post);

    expect($thread->pluck('depth')->all())->toBe([0, 1, 2, 3, 0])
        ->and($thread[2]->quoted->id)->toBe($thread[0]->id)
        ->and($comments->thread($olga, $post))->toBeEmpty()
        ->and(fn () => $comments->add($olga, $post, 'Și Botanica vine'))->toThrow(AuthorizationException::class)
        ->and(fn () => $comments->react($olga, $thread[0], 'like'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManagePosts::class)->vote($olga, $post, $post->pollOptions()->firstOrFail()->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(Moderation::class)->report($olga, $thread[0], 'spam'))->toThrow(AuthorizationException::class)
        // The off-topic comment was hidden by the author of the post: it keeps its place and loses its text for the others.
        ->and($thread[4]->isHidden())->toBeTrue()
        ->and($thread[4]->hidden_by_user_id)->toBe(Personas::user('branch_a_employee_1')->id);

    $this->actingAs(Personas::user('branch_a_employee_2'))->get('/admin/feed?post='.$post->id)->assertOk()
        ->assertSee($thread[0]->body)->assertSee(__('social.ui.comment_hidden'))->assertDontSee($thread[4]->body);
    $this->flushSession();
    $this->actingAs($olga)->get('/admin/feed?post='.$post->id)->assertOk()->assertDontSee($thread[0]->body);
    expect(Comment::query()->where('post_id', $post->id)->count())->toBe(5);
});

it('lets an author address only the audiences within their rights', function () {
    $ion = Personas::user('branch_a_employee_1');
    $posts = app(ManagePosts::class);
    $to = fn (string $code): array => ['body' => 'X', 'visibility' => Post::REGIONAL, 'territory_ids' => [Personas::territory($code)->id]];

    expect(fn () => $posts->create($ion, ['body' => 'X', 'visibility' => Post::PUBLIC]))->toThrow(AuthorizationException::class)
        ->and(fn () => $posts->create($ion, $to('chisinau/sectorul-botanica')))->toThrow(AuthorizationException::class)
        ->and(fn () => $posts->create($ion, $to('chisinau')))->toThrow(AuthorizationException::class)
        ->and(fn () => $posts->create($ion, ['body' => 'X', 'visibility' => Post::TARGETED, 'role_codes' => ['unit_head']]))->toThrow(AuthorizationException::class)
        ->and(fn () => $posts->create(Personas::user('balti_head'), $to('chisinau')))->toThrow(AuthorizationException::class)
        ->and($posts->create(Personas::user('chisinau_head'), $to('chisinau/sectorul-botanica'))->visibility)->toBe(Post::REGIONAL)
        // "All regional heads": the holders of the role, whatever their region — and nobody else.
        ->and(seesPost('branch_a_head', Social::TO_HEADS))->toBeTrue()
        ->and(seesPost('balti_head', Social::TO_HEADS))->toBeTrue()
        ->and(seesPost('branch_a_employee_1', Social::TO_HEADS))->toBeFalse()
        ->and(seesPost('hr', Social::TO_HEADS))->toBeFalse()
        ->and(seesPost('branch_a_employee_2', Social::TO_PEOPLE))->toBeTrue()
        ->and(seesPost('branch_a_employee_3', Social::TO_PEOPLE))->toBeFalse()
        ->and(seesPost('branch_a_head', Social::PRIVATE_NOTE))->toBeFalse()
        ->and(seesPost('super_admin', Social::PRIVATE_NOTE))->toBeFalse();
});

it('keeps a draft and a scheduled post to their authors, and publishes the scheduled one on time', function () {
    $scheduled = Social::post(Social::SCHEDULED);

    expect(Social::post(Social::DRAFT)->status)->toBe(Post::DRAFT)
        ->and(feedOf('branch_a_employee_1', Feed::MINE))->toContain(Social::post(Social::DRAFT)->id)
        ->and(feedOf('branch_a_employee_1'))->not->toContain(Social::post(Social::DRAFT)->id)
        ->and(seesPost('branch_a_employee_2', Social::SCHEDULED))->toBeFalse()
        ->and(seesPost('branch_a_employee_2', Social::DRAFT))->toBeFalse();

    $this->travel(3)->days();
    $this->artisan('social:tick')->assertSuccessful();

    expect($scheduled->fresh()->status)->toBe(Post::PUBLISHED)
        ->and(seesPost('branch_a_employee_2', Social::SCHEDULED))->toBeTrue()
        ->and(seesPost('branch_b_employee_1', Social::SCHEDULED))->toBeFalse();
});

it('shows under "important" the pins of the viewer\'s own places only', function () {
    $welcome = Social::post(Social::WELCOME)->id;
    $chisinau = Social::post(Social::CHISINAU)->id;
    $inGroup = Social::post(Social::IN_OPEN_GROUP)->id;

    expect(PostPin::query()->pluck('scope')->all())->toEqualCanonicalizing([PostPin::GLOBAL, PostPin::TERRITORY, PostPin::GROUP])
        ->and(feedOf('branch_a_employee_2', Feed::IMPORTANT))->toEqualCanonicalizing([$welcome, $chisinau, $inGroup])
        ->and(feedOf('branch_b_employee_3', Feed::IMPORTANT))->toEqualCanonicalizing([$welcome, $chisinau])
        ->and(feedOf('balti_employee_1', Feed::IMPORTANT))->toBe([$welcome])
        ->and(fn () => app(ManagePosts::class)->pin(Personas::user('branch_a_head'), Social::post(Social::WELCOME), PostPin::GLOBAL))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManagePosts::class)->pin(Personas::user('branch_a_employee_1'), Social::post(Social::CENTRU_POLL), PostPin::TERRITORY, Personas::territory('chisinau/sectorul-centru')->id))
        ->toThrow(AuthorizationException::class);
});

it('keeps the edit history of a post: free for the author, journaled for a moderator, closed to the rest', function () {
    $post = Social::post(Social::EDITED);
    $moderation = app(Moderation::class);
    $viewed = fn (): int => JournalEntry::query()->where('event_type', 'social.revisions.viewed')->count();

    expect($post->edited_at)->not->toBeNull()
        ->and($moderation->revisions(Personas::user('branch_a_employee_2'), $post)->pluck('body')->all())
        ->toBe(['Punctul de colectare rămâne pe strada Pușkin.', 'Punctul de colectare se mută pe strada Columna, nr. 12.'])
        ->and($viewed())->toBe(0)
        ->and($moderation->revisions(Personas::user('branch_a_head'), $post))->toHaveCount(2)
        ->and($viewed())->toBe(1)
        ->and(fn () => $moderation->revisions(Personas::user('branch_b_head'), $post))->toThrow(AuthorizationException::class)
        ->and(fn () => $moderation->revisions(Personas::user('branch_a_employee_1'), $post))->toThrow(AuthorizationException::class)
        // A repost shows the original only to those who may see the original itself.
        ->and(Social::post(Social::REPOST)->original->id)->toBe(Social::post(Social::WELCOME)->id);
});

it('speaks three languages and uses every reaction of the catalog', function () {
    expect(Social::post(Social::HR_NOTICE)->visibility)->toBe(Post::PUBLIC)       // ru
        ->and(Social::post(Social::RULES)->visibility)->toBe(Post::PUBLIC)       // en
        ->and(Social::post(Social::WELCOME)->visibility)->toBe(Post::PUBLIC)     // ro
        ->and(Post::query()->pluck('visibility')->unique()->values()->all())->toEqualCanonicalizing(Post::VISIBILITIES)
        ->and(Post::query()->pluck('status')->unique()->values()->all())->toEqualCanonicalizing([Post::PUBLISHED, Post::DRAFT, Post::SCHEDULED])
        ->and(Reaction::query()->pluck('reaction_code')->unique()->values()->all())->toEqualCanonicalizing(['like', 'support', 'important', 'thanks', 'celebrate'])
        ->and(Social::post(Social::IN_OPEN_GROUP)->attachments()->sole()->original_name)->toBe('program-voluntari.txt');

    $url = route('social.attachment', Social::post(Social::IN_OPEN_GROUP)->attachments()->sole());
    $this->actingAs(Personas::user('volunteer'))->get($url)->assertOk();
    $this->flushSession();
    $this->actingAs(Personas::user('balti_employee_1'))->get($url)->assertForbidden();
});
