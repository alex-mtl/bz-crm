<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\ActingAs;
use App\Domain\Audit\Enums\ActorType;
use Illuminate\Support\Str;

/**
 * Who is acting and in which chain of actions. One instance per request / job / command (scoped binding).
 * Values not set explicitly are resolved at write time (e.g. the authenticated user).
 */
final class JournalContext
{
    public ?string $requestId = null;

    public ?string $correlationId = null;

    public ?string $ipAddress = null;

    public ?string $userAgent = null;

    public ?ActorType $actorType = null;

    public ?int $actorUserId = null;

    public ?int $actorPersonId = null;

    public ActingAs $actingAs = ActingAs::Own;

    public ?string $actingAsRef = null;

    /** @var array<string, mixed> */
    public array $extra = [];

    public function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::uuid();
    }

    public function startCorrelation(?string $id = null): string
    {
        return $this->correlationId = $id ?? (string) Str::uuid();
    }

    public function asSystem(string $origin): self
    {
        $this->actorType = ActorType::System;
        $this->actorUserId = null;
        $this->actorPersonId = null;
        $this->extra['origin'] = $origin;

        return $this;
    }

    /**
     * An action performed on a user's behalf outside a request (e.g. the demo seeder replaying the world's history).
     * The origin stays in the entry's context, so such entries remain distinguishable.
     */
    public function asUser(int $userId, ?int $personId): self
    {
        $this->actorType = ActorType::User;
        $this->actorUserId = $userId;
        $this->actorPersonId = $personId;

        return $this;
    }

    public function asGuest(): self
    {
        $this->actorType = ActorType::Guest;
        $this->actorUserId = null;
        $this->actorPersonId = null;

        return $this;
    }

    public function reset(): void
    {
        foreach (get_class_vars(self::class) as $name => $default) {
            $this->{$name} = $default;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toQueuePayload(): array
    {
        return [
            'correlation_id' => $this->correlationId(),
            'actor_user_id' => $this->actorUserId,
            'actor_person_id' => $this->actorPersonId,
            'acting_as' => $this->actingAs->value,
            'acting_as_ref' => $this->actingAsRef,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function restoreFromQueuePayload(array $payload): void
    {
        $this->reset();
        $this->startCorrelation($payload['correlation_id'] ?? null);
        $this->actorType = ActorType::Job;
        $this->actorUserId = $payload['actor_user_id'] ?? null;
        $this->actorPersonId = $payload['actor_person_id'] ?? null;
        $this->actingAs = ActingAs::tryFrom((string) ($payload['acting_as'] ?? '')) ?? ActingAs::Own;
        $this->actingAsRef = $payload['acting_as_ref'] ?? null;
    }
}
