<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A pinned post: globally, in a territory or in a group (ФО §6.4.1).
 *
 * @property int $id
 * @property int $post_id
 * @property string $scope
 * @property int|null $scope_id
 * @property int|null $pinned_by_user_id
 */
class PostPin extends Model
{
    public const string GLOBAL = 'global';

    public const string TERRITORY = 'territory';

    public const string GROUP = 'group';

    protected $table = 'post_pins';

    protected $fillable = ['post_id', 'scope', 'scope_id', 'pinned_by_user_id'];
}
