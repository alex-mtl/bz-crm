<?php

declare(strict_types=1);

namespace App\Domain\Geo\Actions;

use App\Domain\Geo\Models\Apartment;
use App\Domain\Geo\Models\FieldOperation;
use App\Domain\Identity\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The server side of the offline queue (ТЗ §34). Each operation carries an id made on the device; the ledger
 * remembers every id with its outcome, so an operation sent twice — after a lost answer, a double tap, a retry —
 * is applied once and answered the same way every time. The visit and the ledger row are written in one
 * transaction: there is never a visit without its row, or a row without its visit.
 *
 * Only the operations named here work offline; nothing else of the system does.
 */
final readonly class OfflineSync
{
    public const int MAX_BATCH = 200;

    /** entity => operations that may come from the offline queue */
    public const array OPERATIONS = ['apartment' => ['visit', 'note']];

    public function __construct(private RecordVisits $visits) {}

    /**
     * @param  list<mixed>  $operations
     * @return list<array{operation_id: string|null, status: string, duplicate: bool, result?: array<string, mixed>|null, error?: string|null}>
     */
    public function apply(User $user, string $deviceId, array $operations): array
    {
        $answers = [];
        foreach (array_slice($operations, 0, self::MAX_BATCH) as $operation) {
            $answers[] = $this->one($user, $deviceId, is_array($operation) ? $operation : []);
        }

        return $answers;
    }

    /**
     * @param  array<array-key, mixed>  $operation
     * @return array{operation_id: string|null, status: string, duplicate: bool, result?: array<string, mixed>|null, error?: string|null}
     */
    private function one(User $user, string $deviceId, array $operation): array
    {
        $id = is_string($operation['operation_id'] ?? null) ? strtolower($operation['operation_id']) : null;
        if ($id === null || ! Str::isUuid($id)) {
            // Without an id the operation cannot be remembered — and must not be applied.
            return ['operation_id' => null, 'status' => FieldOperation::REJECTED, 'duplicate' => false, 'error' => __('geo.errors.operation_invalid')];
        }

        $known = FieldOperation::query()->where('operation_id', $id)->first();
        if ($known !== null) {
            return $this->again($user, $known);
        }

        $entity = (string) ($operation['entity'] ?? '');
        $name = (string) ($operation['operation'] ?? '');
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $row = [
            'operation_id' => $id, 'device_id' => Str::limit($deviceId, 64, ''), 'user_id' => $user->id, 'entity' => Str::limit($entity, 30, ''),
            'entity_id' => is_numeric($operation['entity_id'] ?? null) ? (int) $operation['entity_id'] : null,
            'operation' => Str::limit($name, 30, ''), 'payload' => $payload,
            'client_timestamp' => $this->timestamp($operation['client_timestamp'] ?? null),
        ];

        try {
            return DB::transaction(function () use ($user, $row, $entity, $name, $payload): array {
                $result = $this->perform($user, $entity, $name, $row['entity_id'], $payload, $row['operation_id'], $row['client_timestamp']);
                FieldOperation::query()->create([...$row, 'status' => FieldOperation::APPLIED, 'result' => $result]);

                return ['operation_id' => $row['operation_id'], 'status' => FieldOperation::APPLIED, 'duplicate' => false, 'result' => $result];
            });
        } catch (UniqueConstraintViolationException) {
            // The same operation is being applied by a parallel request: its outcome is the answer.
            $known = FieldOperation::query()->where('operation_id', $id)->first();

            return $known !== null ? $this->again($user, $known)
                : ['operation_id' => $id, 'status' => FieldOperation::REJECTED, 'duplicate' => false, 'error' => __('geo.errors.operation_invalid')];
        } catch (AuthorizationException|DomainException $refusal) {
            // A refusal is final — sending the operation again would not change it. It is remembered too.
            $error = $refusal->getMessage() !== '' ? $refusal->getMessage() : __('access.denied');
            try {
                FieldOperation::query()->create([...$row, 'status' => FieldOperation::REJECTED, 'result' => ['error' => $error]]);
            } catch (UniqueConstraintViolationException) {
                // Remembered by a parallel request already.
            }

            return ['operation_id' => $id, 'status' => FieldOperation::REJECTED, 'duplicate' => false, 'error' => $error];
        }
    }

    /**
     * @return array{operation_id: string|null, status: string, duplicate: bool, result?: array<string, mixed>|null, error?: string|null}
     */
    private function again(User $user, FieldOperation $known): array
    {
        if ($known->user_id !== $user->id) {
            // Somebody else's id: neither applied nor disclosed.
            return ['operation_id' => $known->operation_id, 'status' => FieldOperation::REJECTED, 'duplicate' => true, 'error' => __('access.denied')];
        }
        $known->increment('received_count');

        return $known->status === FieldOperation::APPLIED
            ? ['operation_id' => $known->operation_id, 'status' => FieldOperation::APPLIED, 'duplicate' => true, 'result' => $known->result]
            : ['operation_id' => $known->operation_id, 'status' => FieldOperation::REJECTED, 'duplicate' => true, 'error' => (string) ($known->result['error'] ?? '')];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function perform(User $user, string $entity, string $operation, ?int $entityId, array $payload, string $operationId, ?string $clientTime): array
    {
        if (! in_array($operation, self::OPERATIONS[$entity] ?? [], true)) {
            throw new DomainException(__('geo.errors.operation_unknown'));
        }
        $apartment = $entityId !== null ? Apartment::query()->with('house')->find($entityId) : null;
        if ($apartment === null) {
            // "Not found" and "not yours" answer alike.
            throw new AuthorizationException(__('access.denied'));
        }

        if ($operation === 'note') {
            $note = $this->visits->note($user, $apartment, (string) ($payload['note'] ?? ''), is_string($payload['note_visibility'] ?? null) ? $payload['note_visibility'] : null);

            return ['note_id' => $note->id, 'apartment' => $this->state($apartment)];
        }

        $visit = $this->visits->record($user, $apartment, [
            'status_code' => (string) ($payload['status_code'] ?? ''),
            'note' => is_string($payload['note'] ?? null) ? $payload['note'] : null,
            'note_visibility' => is_string($payload['note_visibility'] ?? null) ? $payload['note_visibility'] : null,
            'next_visit_on' => is_string($payload['next_visit_on'] ?? null) ? $payload['next_visit_on'] : null,
            'create_task' => (bool) ($payload['create_task'] ?? false),
            'visited_at' => $clientTime,
        ], $operationId);

        return ['visit_id' => $visit->id, 'apartment' => $this->state($apartment)];
    }

    /**
     * @return array<string, mixed>
     */
    private function state(Apartment $apartment): array
    {
        $fresh = $apartment->fresh() ?? $apartment;

        return [
            'id' => $fresh->id, 'status' => $fresh->status_code, 'attempts' => $fresh->attempts,
            'last_visit_at' => $fresh->last_visit_at?->toIso8601String(), 'next_visit_on' => $fresh->next_visit_on?->toDateString(),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'))->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
