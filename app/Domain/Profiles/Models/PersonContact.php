<?php

declare(strict_types=1);

namespace App\Domain\Profiles\Models;

use App\Domain\Profiles\Enums\FieldVisibility;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact from the extensible contact-type catalog, with the owner's visibility choice (Д-13).
 *
 * @property int $id
 * @property int $person_id
 * @property string $contact_type
 * @property string $value
 * @property FieldVisibility $visibility
 * @property int $sort_order
 * @property bool $is_preferred
 */
class PersonContact extends Model
{
    protected $fillable = ['person_id', 'contact_type', 'value', 'visibility', 'sort_order', 'is_preferred'];

    protected function casts(): array
    {
        return ['visibility' => FieldVisibility::class, 'is_preferred' => 'boolean'];
    }
}
