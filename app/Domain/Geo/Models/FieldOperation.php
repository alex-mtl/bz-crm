<?php

declare(strict_types=1);

namespace App\Domain\Geo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The ledger of offline operations (ТЗ §34): an operation is applied once; when it comes again, the stored
 * answer is given back.
 *
 * @property int $id
 * @property string $operation_id
 * @property string $device_id
 * @property int $user_id
 * @property string $entity
 * @property int|null $entity_id
 * @property string $operation
 * @property array<string, mixed> $payload
 * @property Carbon|null $client_timestamp
 * @property string $status
 * @property array<string, mixed>|null $result
 * @property int $received_count
 */
class FieldOperation extends Model
{
    public const string APPLIED = 'applied';

    public const string REJECTED = 'rejected';

    protected $fillable = [
        'operation_id', 'device_id', 'user_id', 'entity', 'entity_id', 'operation', 'payload', 'client_timestamp',
        'status', 'result', 'received_count',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'client_timestamp' => 'datetime', 'received_count' => 'integer'];
    }
}
