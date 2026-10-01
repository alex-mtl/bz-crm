<?php

use App\Domain\Audit\Actions\PurgeExpiredJournalEntries;
use App\Domain\Audit\Actions\UpdateRetentionPolicy;
use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\EventJournal;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Audit\Exceptions\JournalIsImmutable;
use App\Domain\Audit\Exceptions\UnknownEventType;
use App\Domain\Audit\JournalContext;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Jobs\RecordJournalEventJob;

beforeEach(function () {
    $registry = app(EventTypeRegistry::class);
    foreach ([
        new EventType('test.thing.done', EventCategory::Business),
        new EventType('test.secret.changed', EventCategory::Admin, EventSeverity::Notice, ['pin']),
        new EventType('test.job.ran', EventCategory::Business),
    ] as $type) {
        if (! $registry->has($type->code)) {
            $registry->register($type);
        }
    }
});

function journal(): EventJournal
{
    return app(EventJournal::class);
}

it('writes an entry with category and severity from the event type catalog', function () {
    $entry = journal()->record('test.secret.changed', ['type' => 'thing', 'id' => 42]);

    expect($entry->category)->toBe(EventCategory::Admin)
        ->and($entry->severity)->toBe(EventSeverity::Notice)
        ->and($entry->subject_type)->toBe('thing')
        ->and($entry->subject_id)->toBe('42')
        ->and($entry->correlation_id)->not->toBeEmpty();
});

it('records the authenticated user as the actor', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $entry = journal()->record('test.thing.done');

    expect($entry->actor_type)->toBe(ActorType::User)
        ->and($entry->actor_user_id)->toBe($user->id);
});

it('rejects unregistered event types', function () {
    journal()->record('nobody.registered.this');
})->throws(UnknownEventType::class);

it('rejects registering the same event type twice', function () {
    app(EventTypeRegistry::class)->register(new EventType('test.thing.done', EventCategory::Business));
})->throws(UnknownEventType::class);

it('cannot be changed through the application', function () {
    $entry = journal()->record('test.thing.done');
    $entry->update(['event_type' => 'test.secret.changed']);
})->throws(JournalIsImmutable::class);

it('cannot be deleted through the application', function () {
    journal()->record('test.thing.done')->delete();
})->throws(JournalIsImmutable::class);

it('is rolled back together with the action it describes', function () {
    try {
        DB::transaction(function () {
            journal()->record('test.thing.done');
            throw new RuntimeException('action failed');
        });
    } catch (RuntimeException) {
    }

    expect(JournalEntry::query()->where('event_type', 'test.thing.done')->count())->toBe(0);
});

it('masks secrets, including type-specific and nested fields', function () {
    $entry = journal()->record(
        'test.secret.changed',
        null,
        ['password' => 'old-pass', 'pin' => '1234'],
        ['client_secret' => 'abc', 'nested' => ['token' => 'xyz', 'visible' => 'ok'], 'name' => 'Ion'],
    );

    expect($entry->old_values)->toBe(['password' => '***', 'pin' => '***'])
        ->and($entry->new_values)->toBe(['client_secret' => '***', 'nested' => ['token' => '***', 'visible' => 'ok'], 'name' => 'Ion']);
});

it('takes request id, ip and correlation from the http request', function () {
    Route::get('/_test/journal', function () {
        journal()->record('test.thing.done');
        journal()->record('test.thing.done');

        return 'ok';
    });

    $response = $this->get('/_test/journal', ['X-Request-Id' => '11111111-2222-4333-8444-555555555555']);

    $response->assertHeader('X-Request-Id', '11111111-2222-4333-8444-555555555555');
    $entries = JournalEntry::query()->where('event_type', 'test.thing.done')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('correlation_id')->unique()->all())->toBe(['11111111-2222-4333-8444-555555555555'])
        ->and($entries->first()->ip_address)->toBe('127.0.0.1');
});

it('keeps the correlation and the initiating user in queued jobs', function () {
    config(['queue.default' => 'database']);
    $user = User::factory()->create();
    $this->actingAs($user);
    $correlation = app(JournalContext::class)->startCorrelation();
    app(JournalContext::class)->actorUserId = $user->id;

    RecordJournalEventJob::dispatch();
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    $entry = JournalEntry::query()->where('event_type', 'test.job.ran')->sole();
    expect($entry->actor_type)->toBe(ActorType::Job)
        ->and($entry->actor_user_id)->toBe($user->id)
        ->and($entry->correlation_id)->toBe($correlation);
});

it('records console commands as the system actor', function () {
    app(UpdateRetentionPolicy::class)(['business' => 30]);
    DB::table('journal_entries')->insert([
        'occurred_at' => now()->subDays(40), 'event_type' => 'test.thing.done', 'category' => 'business',
        'severity' => 'info', 'actor_type' => 'system', 'acting_as' => 'own', 'correlation_id' => 'old',
    ]);

    // A real CLI run fires CommandStarting through Symfony's dispatcher; Artisan::call() does not.
    event(new CommandStarting('journal:purge', new ArrayInput([]), new NullOutput));
    Artisan::call('journal:purge');

    $purge = JournalEntry::query()->where('event_type', 'audit.retention.purged')->sole();
    expect($purge->actor_type)->toBe(ActorType::System)
        ->and($purge->context['origin'])->toBe('console:journal:purge')
        ->and($purge->new_values)->toBe(['deleted' => ['business' => 1]]);
});

it('purges only expired entries of categories that have a retention period', function () {
    app(UpdateRetentionPolicy::class)(['business' => 30]);
    $row = fn (string $category, int $daysAgo) => [
        'occurred_at' => now()->subDays($daysAgo), 'event_type' => 'test.thing.done', 'category' => $category,
        'severity' => 'info', 'actor_type' => 'system', 'acting_as' => 'own', 'correlation_id' => 'x',
    ];
    DB::table('journal_entries')->insert([$row('business', 40), $row('business', 10), $row('security', 400)]);

    expect(app(PurgeExpiredJournalEntries::class)())->toBe(['business' => 1])
        ->and(JournalEntry::query()->where('category', 'security')->count())->toBe(1)
        ->and(JournalEntry::query()->where('event_type', 'audit.settings.updated')->count())->toBe(1);
});

it('keeps everything while no retention period is configured', function () {
    DB::table('journal_entries')->insert([
        'occurred_at' => now()->subYears(10), 'event_type' => 'test.thing.done', 'category' => 'business',
        'severity' => 'info', 'actor_type' => 'system', 'acting_as' => 'own', 'correlation_id' => 'x',
    ]);

    expect(app(PurgeExpiredJournalEntries::class)())->toBe([]);
});
