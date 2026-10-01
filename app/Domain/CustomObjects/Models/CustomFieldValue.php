<?php

declare(strict_types=1);

namespace App\Domain\CustomObjects\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $custom_field_id
 * @property int $entity_id
 * @property string|null $value
 * @property-read CustomField $field
 */
class CustomFieldValue extends Model
{
    protected $fillable = ['custom_field_id', 'entity_id', 'value'];

    /**
     * @return BelongsTo<CustomField, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
}
