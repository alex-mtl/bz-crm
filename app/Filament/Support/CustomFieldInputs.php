<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\CustomObjects\CustomFields;
use App\Domain\CustomObjects\Models\CustomField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;

/**
 * Form inputs for the custom fields of a record (ФО §6.9.1): one input per field, by the field type.
 * Values travel in the form state under "custom.<code>".
 */
final class CustomFieldInputs
{
    /**
     * @return list<Component>
     */
    public static function for(string $entity, ?string $kind = null): array
    {
        return app(CustomFields::class)->definitions($entity, $kind)->map(function (CustomField $field): Component {
            $name = 'custom.'.$field->code;

            return match ($field->field_type) {
                'number' => TextInput::make($name)->label($field->name())->numeric(),
                'date' => DatePicker::make($name)->label($field->name()),
                'bool' => Toggle::make($name)->label($field->name()),
                'select' => Select::make($name)->label($field->name())->options($field->optionLabels()),
                default => TextInput::make($name)->label($field->name())->maxLength(2000),
            };
        })->all();
    }

    /**
     * Stored values as shown to a reader: field name => readable value.
     *
     * @return array<string, string>
     */
    public static function display(string $entity, int $entityId, ?string $kind = null): array
    {
        $values = app(CustomFields::class)->values($entity, $entityId);
        $display = [];
        foreach (app(CustomFields::class)->definitions($entity, $kind) as $field) {
            $value = $values[$field->code] ?? null;
            if ($value === null) {
                continue;
            }
            $display[$field->name()] = match ($field->field_type) {
                'bool' => $value === '1' ? __('admin.yes') : __('admin.no'),
                'select' => $field->optionLabels()[$value] ?? $value,
                default => $value,
            };
        }

        return $display;
    }
}
