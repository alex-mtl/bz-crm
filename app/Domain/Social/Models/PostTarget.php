<?php

declare(strict_types=1);

namespace App\Domain\Social\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a targeted audience: a person or a role (ФО §6.4.1 "целевой список").
 *
 * @property int $id
 * @property int $post_id
 * @property int|null $person_id
 * @property string|null $role_code
 */
class PostTarget extends Model
{
    public $timestamps = false;

    protected $table = 'post_targets';

    protected $fillable = ['post_id', 'person_id', 'role_code'];
}
