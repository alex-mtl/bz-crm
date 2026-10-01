<?php

use App\Domain\Audit\EventJournal;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\Audit\Exceptions\JournalIsImmutable;
use App\Domain\Audit\Exceptions\UnknownEventType;
use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Identity\Actions\SaveAuthProvider;
use App\Domain\Identity\Enums\ApplicationStatus;
use App\Domain\Identity\Models\AuthProvider;
use App\Domain\Identity\Models\RegistrationApplication;
use App\Filament\Resources\Applications\Pages\ListRegistrationApplications;
use Database\Seeders\Demo\Personas;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * IMPLEMENTATION-PLAN 1.4 — event journal, on the demo world (docs/demo/README.md § Журнал).
 */

it('holds the history of the demo world with the right actors', function () {
    $actorOf = fn (string $type) => JournalEntry::query()->where('event_type', $type)->latest('id')->value('actor_user_id');

    expect($actorOf('identity.user.deactivated'))->toBe(Personas::user('hr')->id)
        ->and($actorOf('access.escalation.denied'))->toBe(Personas::user('branch_a_head')->id)
        ->and($actorOf('identity.accounts.linked'))->toBe(Personas::user('hr')->id)
        ->and(JournalEntry::query()->where('event_type', 'identity.registration.submitted')->pluck('actor_type')->unique()->map->value->all())->toBe(['guest']);
});

it('cannot change or delete a journal entry through the application', function () {
    $entry = JournalEntry::query()->where('event_type', 'identity.user.deactivated')->sole();

    expect(fn () => $entry->update(['event_type' => 'x']))->toThrow(JournalIsImmutable::class)
        ->and(fn () => $entry->delete())->toThrow(JournalIsImmutable::class);
});

it('rolls the entry back together with the action', function () {
    $before = JournalEntry::query()->count();

    try {
        DB::transaction(function (): void {
            app(EventJournal::class)->record('identity.profile.updated', Personas::user('hr'));
            throw new RuntimeException('the action failed');
        });
    } catch (RuntimeException) {
    }

    expect(JournalEntry::query()->count())->toBe($before);
});

it('never stores passwords or provider secrets in the journal', function () {
    app(SaveAuthProvider::class)(Personas::user('super_admin'), [
        'code' => 'google', 'driver' => 'google', 'display_name' => 'Google',
        'client_id' => 'demo-client', 'client_secret' => 'super-secret-value-123',
    ], AuthProvider::query()->where('code', 'google')->sole());

    $all = JournalEntry::query()->get(['old_values', 'new_values', 'context'])->toJson();
    expect($all)->not->toContain('super-secret-value-123')
        ->and($all)->not->toContain((string) config('demo.password'));
});

it('hides the journal from a user without audit.read, and journals viewing it', function () {
    $this->actingAs(Personas::user('hr'))->get('/admin/journal')->assertForbidden();

    $before = journalCount('audit.viewed');
    $this->flushSession();
    $this->actingAs(Personas::user('security'))->get('/admin/journal')->assertOk();
    expect(journalCount('audit.viewed'))->toBe($before + 1);
});

it('links all entries of one approval with one correlation id', function () {
    $application = RegistrationApplication::query()->where('status', ApplicationStatus::Pending)->where('channel', 'email')->sole();
    $this->actingAs($hr = Personas::user('hr'));

    Livewire::test(ListRegistrationApplications::class)
        ->callTableAction('approve', $application, ['person_type' => 'volunteer', 'roles' => ['volunteer']]);

    $approved = JournalEntry::query()->where('event_type', 'admission.application.approved')->latest('id')->first();
    $related = JournalEntry::query()->where('correlation_id', $approved->correlation_id)->pluck('event_type')->all();
    expect($related)->toContain('admission.application.approved', 'access.role.assigned')
        ->and($approved->actor_user_id)->toBe($hr->id);
});

it('rejects an unregistered event type; every type has ro/ru/en descriptions', function () {
    expect(fn () => app(EventJournal::class)->record('nobody.registered.this'))->toThrow(UnknownEventType::class);

    foreach (app(EventTypeRegistry::class)->all() as $type) {
        foreach (['ro', 'ru', 'en'] as $locale) {
            expect(trans($type->labelKey(), [], $locale))->not->toBe($type->labelKey());
        }
    }
});
