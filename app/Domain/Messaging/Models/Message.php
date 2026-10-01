<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $chat_id
 * @property int $author_person_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 * @property-read Person $author
 */
class Message extends Model
{
    protected $fillable = ['chat_id', 'author_person_id', 'body', 'edited_at'];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'author_person_id');
    }
}
