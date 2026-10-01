<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\ActingAs;
use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Models\JournalEntry;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The single entry point for writing to the business event journal (Д-4, ADR-006).
 * Call it inside the same DB transaction as the action it describes.
 */
final readonly class EventJournal
{
    public function __construct(
        private EventTypeRegistry $types,
        private JournalContext $context,
        private ValueMasker $masker,
        private AuthFactory $auth,
    ) {}

    /**
     * @param  Model|array{type: string, id: int|string}|null  $subject
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $eventType,
        Model|array|null $subject = null,
        array $old = [],
        array $new = [],
        array $context = [],
    ): JournalEntry {
        $type = $this->types->get($eventType);

        [$subjectType, $subjectId] = match (true) {
            $subject instanceof Model => [$subject->getMorphClass(), (string) $subject->getKey()],
            is_array($subject) => [$subject['type'], (string) $subject['id']],
            default => [null, null],
        };

        [$actorType, $actorUserId, $actorPersonId] = $this->resolveActor();

        $extra = [...$this->context->extra, ...$context];

        return JournalEntry::query()->create([
            'occurred_at' => now(),
            'event_type' => $type->code,
            'category' => $type->category,
            'severity' => $type->severity,
            'actor_type' => $actorType,
            'actor_user_id' => $actorUserId,
            'actor_person_id' => $actorPersonId,
            'acting_as' => $this->context->actingAs,
            'acting_as_ref' => $this->context->actingAsRef,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'old_values' => $old === [] ? null : $this->masker->mask($old, $type->maskedFields),
            'new_values' => $new === [] ? null : $this->masker->mask($new, $type->maskedFields),
            'context' => $extra === [] ? null : $this->masker->mask($extra, $type->maskedFields),
            'ip_address' => $this->context->ipAddress,
            'user_agent' => $this->context->userAgent !== null ? mb_substr($this->context->userAgent, 0, 512) : null,
            'request_id' => $this->context->requestId,
            'correlation_id' => $this->context->correlationId(),
        ]);
    }

    /**
     * @return array{0: ActorType, 1: int|null, 2: int|null}
     */
    private function resolveActor(): array
    {
        if ($this->context->actorType !== null && $this->context->actorType !== ActorType::User) {
            return [$this->context->actorType, $this->context->actorUserId, $this->context->actorPersonId];
        }
        // Impersonation: the impersonator is the actor, not the account signed in (Д-19).
        if ($this->context->actingAs === ActingAs::Impersonation && $this->context->actorUserId !== null) {
            return [ActorType::User, $this->context->actorUserId, $this->context->actorPersonId];
        }

        $user = $this->auth->guard()->user();

        if ($user === null) {
            return [$this->context->actorType ?? ActorType::Guest, $this->context->actorUserId, $this->context->actorPersonId];
        }

        $personId = $user->getAttribute('person_id');

        return [ActorType::User, (int) $user->getAuthIdentifier(), $personId !== null ? (int) $personId : null];
    }
}
