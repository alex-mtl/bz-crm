<?php

declare(strict_types=1);

namespace App\Domain\CustomObjects;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\CustomObjects\Exceptions\CustomFieldViolation;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\CustomObjects\Models\CustomFieldValue;
use App\Domain\Identity\Models\User;
use App\Support\Translation\TranslatedNames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Custom fields: definitions are managed by the administrator, values are validated by type and stored per record.
 * Who may read or change the record itself is decided by the module owning it — values follow the record.
 */
final readonly class CustomFields
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array{code?: string, names: array<string, string|null>, field_type: string, options?: list<array<string, string>>|null,
     *               applies_to?: list<string>|null, sort_order?: int, is_active?: bool}  $data
     */
    public function saveDefinition(User $actor, string $entity, array $data, string $sourceLocale, ?CustomField $field = null): CustomField
    {
        $this->authorization->authorize($actor, 'custom_objects.types.manage');

        $type = $field !== null ? $field->field_type : $data['field_type'];
        if (! in_array($type, CustomField::TYPES, true)) {
            throw CustomFieldViolation::because('invalid_type');
        }
        $names = TranslatedNames::complete($data['names'], $sourceLocale)->names;
        $code = $field !== null ? $field->code : Str::slug((string) ($data['code'] ?? $names[$sourceLocale]), '_');
        if ($code === '' || ($field === null && CustomField::query()->where('entity', $entity)->where('code', $code)->exists())) {
            throw CustomFieldViolation::because('code_taken');
        }
        $options = null;
        if ($type === 'select') {
            $options = array_values(array_filter(array_map(function (array $option): ?array {
                $label = trim((string) ($option['ro'] ?? $option['ru'] ?? $option['en'] ?? ''));
                $value = trim((string) ($option['value'] ?? '')) ?: Str::slug($label, '_');

                return $value === '' ? null : ['value' => $value, 'ro' => $option['ro'] ?? $label, 'ru' => $option['ru'] ?? $label, 'en' => $option['en'] ?? $label];
            }, $data['options'] ?? [])));
            if ($options === []) {
                throw CustomFieldViolation::because('options_required');
            }
        }

        return DB::transaction(function () use ($entity, $field, $code, $type, $names, $options, $data): CustomField {
            $field ??= new CustomField(['entity' => $entity, 'code' => $code, 'field_type' => $type]);
            $field->fill([
                'name_ro' => $names['ro'], 'name_ru' => $names['ru'], 'name_en' => $names['en'],
                'options' => $options,
                'applies_to' => ($data['applies_to'] ?? []) === [] ? null : array_values($data['applies_to']),
                'sort_order' => $data['sort_order'] ?? $field->sort_order ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ])->save();
            $this->journal->record('custom_fields.definition.saved', $field, [], ['entity' => $entity, 'code' => $code, 'field_type' => $type]);

            return $field;
        });
    }

    /**
     * Active fields for a record of this kind.
     *
     * @return Collection<int, CustomField>
     */
    public function definitions(string $entity, ?string $kind = null): Collection
    {
        return CustomField::query()->for($entity)->where('is_active', true)->get()
            ->filter(fn (CustomField $field): bool => $kind === null || $field->appliesTo($kind))->values();
    }

    /**
     * @return array<string, string|null> field code => stored value
     */
    public function values(string $entity, int $entityId): array
    {
        return CustomFieldValue::query()->where('entity_id', $entityId)
            ->whereHas('field', fn ($q) => $q->where('entity', $entity))
            ->with('field')->get()
            ->mapWithKeys(fn (CustomFieldValue $value): array => [$value->field->code => $value->value])->all();
    }

    /**
     * Validates and normalizes values without saving — the same check the import uses for its dry run.
     *
     * @param  array<string, mixed>  $values  field code => raw value
     * @return array<string, string|null> field code => value to store
     */
    public function normalize(string $entity, array $values, ?string $kind = null): array
    {
        $fields = CustomField::query()->for($entity)->where('is_active', true)->get()->keyBy('code');
        $normalized = [];
        foreach ($values as $code => $raw) {
            $field = $fields->get($code);
            if ($field === null) {
                throw CustomFieldViolation::because('unknown_field', ['code' => $code]);
            }
            if (! $field->appliesTo($kind) && filled($raw)) {
                throw CustomFieldViolation::because('not_applicable', ['field' => $field->name()]);
            }
            $normalized[$code] = $this->normalizeOne($field, $raw);
        }

        return $normalized;
    }

    /**
     * Stores values of a record. The caller has already authorized changing the record.
     *
     * @param  array<string, mixed>  $values
     */
    public function store(string $entity, int $entityId, array $values, ?string $kind = null): void
    {
        $normalized = $this->normalize($entity, $values, $kind);
        $fields = CustomField::query()->for($entity)->whereIn('code', array_keys($normalized))->get()->keyBy('code');

        foreach ($normalized as $code => $value) {
            $fieldId = $fields[$code]->id;
            if ($value === null) {
                CustomFieldValue::query()->where('custom_field_id', $fieldId)->where('entity_id', $entityId)->delete();
            } else {
                CustomFieldValue::query()->updateOrCreate(['custom_field_id' => $fieldId, 'entity_id' => $entityId], ['value' => $value]);
            }
        }
    }

    private function normalizeOne(CustomField $field, mixed $raw): ?string
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        $value = is_bool($raw) ? ($raw ? '1' : '0') : trim((string) $raw);
        $invalid = fn () => CustomFieldViolation::because('invalid_value', ['field' => $field->name()]);

        switch ($field->field_type) {
            case 'number':
                $number = str_replace(',', '.', $value);
                if (! is_numeric($number)) {
                    throw $invalid();
                }

                return (string) ($number + 0);
            case 'date':
                try {
                    return CarbonImmutable::parse($value)->toDateString();
                } catch (Throwable) {
                    throw $invalid();
                }
            case 'bool':
                $truthy = ['1', 'true', 'yes', 'da', 'да'];
                $falsy = ['0', 'false', 'no', 'nu', 'нет'];
                $lower = Str::lower($value);
                if (! in_array($lower, [...$truthy, ...$falsy], true)) {
                    throw $invalid();
                }

                return in_array($lower, $truthy, true) ? '1' : '0';
            case 'select':
                foreach ($field->options ?? [] as $option) {
                    if ($value === $option['value'] || in_array($value, [$option['ro'], $option['ru'], $option['en']], true)) {
                        return $option['value'];
                    }
                }
                throw $invalid();
            default:
                return mb_substr($value, 0, 2000);
        }
    }
}
