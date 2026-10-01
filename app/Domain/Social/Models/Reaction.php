<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A reaction to a post or a comment (ФО §6.4.3). One per person per item; the set of reactions is a catalog.
 *
 * @property int $id
 * @property string $reactable_type
 * @property int $reactable_id
 * @property int $person_id
 * @property string $reaction_code
 */
class Reaction extends Model
{
    public const string POST = 'post';

    public const string COMMENT = 'comment';

    protected $table = 'reactions';

    protected $fillable = ['reactable_type', 'reactable_id', 'person_id', 'reaction_code'];
}
