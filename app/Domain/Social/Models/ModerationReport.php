<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A complaint about a post or a comment (ФО §6.4.4). It waits in the moderation queue until upheld or dismissed.
 *
 * @property int $id
 * @property string $reportable_type
 * @property int $reportable_id
 * @property int $reporter_person_id
 * @property string $reason_code
 * @property string|null $comment
 * @property string $status
 * @property int|null $resolved_by_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 */
class ModerationReport extends Model
{
    public const string OPEN = 'open';

    public const string UPHELD = 'upheld';

    public const string DISMISSED = 'dismissed';

    protected $table = 'moderation_reports';

    protected $attributes = ['status' => self::OPEN];

    protected $fillable = ['reportable_type', 'reportable_id', 'reporter_person_id', 'reason_code', 'comment', 'status', 'resolved_by_user_id', 'resolved_at'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'reporter_person_id');
    }
}
