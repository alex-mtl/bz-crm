<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Audit\Enums\ActingAs;
use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\Enums\EventSeverity;
use App\Domain\Audit\Exceptions\JournalIsImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $occurred_at
 * @property string $event_type
 * @property EventCategory $category
 * @property EventSeverity $severity
 * @property ActorType $actor_type
 * @property int|null $actor_user_id
 * @property int|null $actor_person_id
 * @property ActingAs $acting_as
 * @property string|null $acting_as_ref
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property string $correlation_id
 */
class JournalEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw JournalIsImmutable::make());
        static::deleting(fn () => throw JournalIsImmutable::make());
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'category' => EventCategory::class,
            'severity' => EventSeverity::class,
            'actor_type' => ActorType::class,
            'acting_as' => ActingAs::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'context' => 'array',
        ];
    }
}
