<?php

declare(strict_types=1);

namespace App\Domain\People\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * History of a person's status (catalog §3.2, people.status_history.read): type changes, archiving, merges.
 *
 * @property int $id
 * @property int $person_id
 * @property string $kind
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string|null $note
 * @property int|null $changed_by_user_id
 * @property Carbon $created_at
 */
class PersonStatusHistory extends Model
{
    public const string TYPE = 'type';

    public const string ARCHIVED = 'archived';

    public const string RESTORED = 'restored';

    public const string MERGED = 'merged';

    public const null UPDATED_AT = null;

    protected $table = 'person_status_history';

    protected $fillable = ['person_id', 'kind', 'old_value', 'new_value', 'note', 'changed_by_user_id'];
}
