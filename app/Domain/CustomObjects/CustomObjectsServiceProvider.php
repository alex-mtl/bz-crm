<?php

declare(strict_types=1);

namespace App\Domain\CustomObjects;

use App\Domain\Audit\Enums\EventCategory;
use App\Domain\Audit\EventType;
use App\Domain\Audit\EventTypeRegistry;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\People\PersonReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

final class CustomObjectsServiceProvider extends ServiceProvider
{
    public function boot(EventTypeRegistry $types): void
    {
        $types->register(new EventType('custom_fields.definition.saved', EventCategory::Admin));

        // Merging two cards: values the kept card lacks move over, the rest stay recorded in the merge.
        $this->callAfterResolving(PersonReferences::class, function (PersonReferences $references): void {
            $references->registerHandler('custom_field_values', function (int $keptId, int $mergedId): array {
                $fieldIds = CustomField::query()->where('entity', CustomField::PERSON)->pluck('id');
                $kept = DB::table('custom_field_values')->whereIn('custom_field_id', $fieldIds)->where('entity_id', $keptId)->pluck('custom_field_id')->all();
                $rows = DB::table('custom_field_values')->whereIn('custom_field_id', $fieldIds)->where('entity_id', $mergedId)->get();

                $moved = ['moved' => [], 'kept_own' => []];
                foreach ($rows as $row) {
                    if (in_array($row->custom_field_id, $kept, true)) {
                        $moved['kept_own'][] = (array) $row;
                        DB::table('custom_field_values')->where('id', $row->id)->delete();
                    } else {
                        DB::table('custom_field_values')->where('id', $row->id)->update(['entity_id' => $keptId]);
                        $moved['moved'][] = $row->id;
                    }
                }

                return array_filter($moved);
            });
        });
    }
}
