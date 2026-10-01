<?php

declare(strict_types=1);

namespace App\Domain\CRM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One line of an import file with the verdict of validation.
 *
 * @property int $id
 * @property int $import_batch_id
 * @property int $row_number
 * @property array<string, mixed> $data
 * @property string $status
 * @property list<string>|null $errors
 * @property int|null $duplicate_of_person_id
 * @property int|null $person_id
 */
class ImportRow extends Model
{
    public const string VALID = 'valid';

    public const string ERROR = 'error';

    public const string DUPLICATE = 'duplicate';

    public const string IMPORTED = 'imported';

    public const string SKIPPED = 'skipped';

    protected $fillable = ['import_batch_id', 'row_number', 'data', 'status', 'errors', 'duplicate_of_person_id', 'person_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'errors' => 'array'];
    }
}
