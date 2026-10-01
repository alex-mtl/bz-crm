<?php

declare(strict_types=1);

namespace App\Domain\CRM;

use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CustomObjects\Models\CustomField;
use App\Domain\Geo\Models\Territory;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Turns the criteria of a segment (ФО §6.9.4) into a query over people. The query knows nothing about access:
 * the caller narrows it to what the reader may see.
 *
 * Criteria (all optional, combined with AND):
 *   person_types: list<string> · territory_id: int (with everything below it) · unit_id: int (with child units)
 *   age_from / age_to: int · gender: string · languages: list<string> (any of)
 *   source_code: string · created_from / created_to: date · search: string
 *   pipeline_id: int [+ stage_id: int] [+ lead_status: string] · interaction_kind: string [+ interaction_days: int]
 *   custom: array<field code, value> · include_archived: bool
 */
final class SegmentQuery
{
    public const array KEYS = [
        'person_types', 'territory_id', 'unit_id', 'age_from', 'age_to', 'gender', 'languages', 'source_code',
        'created_from', 'created_to', 'search', 'pipeline_id', 'stage_id', 'lead_status', 'interaction_kind',
        'interaction_days', 'custom', 'include_archived',
    ];

    /**
     * Drops empty values and unknown keys, so what is stored is exactly what is applied.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public function clean(array $criteria): array
    {
        $clean = [];
        foreach (self::KEYS as $key) {
            $value = $criteria[$key] ?? null;
            if (is_array($value)) {
                $filtered = array_filter($value, fn ($v): bool => filled($v));
                $value = array_is_list($value) ? array_values($filtered) : $filtered;
            }
            if ($value === null || $value === '' || $value === [] || $value === false) {
                continue;
            }
            $clean[$key] = $value;
        }
        if (isset($clean['age_from'], $clean['age_to']) && (int) $clean['age_from'] > (int) $clean['age_to']) {
            throw CrmRuleViolation::because('segment_age_range');
        }
        if ((isset($clean['stage_id']) || isset($clean['lead_status'])) && ! isset($clean['pipeline_id'])) {
            throw CrmRuleViolation::because('segment_stage_needs_pipeline');
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return Builder<Person>
     */
    public function build(array $criteria): Builder
    {
        $criteria = $this->clean($criteria);
        $query = Person::query()->whereNull('people.duplicate_of_person_id');

        if (! ($criteria['include_archived'] ?? false)) {
            $query->whereNull('people.archived_at');
        }
        if (isset($criteria['person_types'])) {
            $query->whereIn('people.person_type', (array) $criteria['person_types']);
        }
        if (isset($criteria['source_code'])) {
            $query->where('people.source_code', $criteria['source_code']);
        }
        if (isset($criteria['created_from'])) {
            $query->where('people.created_at', '>=', Carbon::parse($criteria['created_from'])->startOfDay());
        }
        if (isset($criteria['created_to'])) {
            $query->where('people.created_at', '<=', Carbon::parse($criteria['created_to'])->endOfDay());
        }
        if (isset($criteria['search'])) {
            $term = '%'.$criteria['search'].'%';
            $query->where(fn (Builder $w) => $w->where('people.first_name', 'like', $term)->orWhere('people.last_name', 'like', $term));
        }

        if (isset($criteria['territory_id'])) {
            $this->inTerritory($query, (int) $criteria['territory_id']);
        }
        if (isset($criteria['unit_id'])) {
            $this->inUnit($query, (int) $criteria['unit_id']);
        }

        $profile = fn (callable $where) => $query->whereExists(fn (QueryBuilder $sub) => $where(
            $sub->selectRaw('1')->from('person_profiles')->whereColumn('person_profiles.person_id', 'people.id')));
        // "Older than 30" means the 30th birthday has passed: born on or before today minus 30 years.
        if (isset($criteria['age_from'])) {
            $profile(fn (QueryBuilder $sub) => $sub->where('person_profiles.birth_date', '<=', today()->subYears((int) $criteria['age_from'])));
        }
        if (isset($criteria['age_to'])) {
            $profile(fn (QueryBuilder $sub) => $sub->where('person_profiles.birth_date', '>', today()->subYears((int) $criteria['age_to'] + 1)));
        }
        if (isset($criteria['gender'])) {
            $profile(fn (QueryBuilder $sub) => $sub->where('person_profiles.gender', $criteria['gender']));
        }
        if (isset($criteria['languages'])) {
            $profile(fn (QueryBuilder $sub) => $sub->where(function (QueryBuilder $any) use ($criteria): void {
                foreach ((array) $criteria['languages'] as $language) {
                    $any->orWhereJsonContains('person_profiles.languages', $language);
                }
            }));
        }

        if (isset($criteria['pipeline_id'])) {
            $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('leads')
                ->whereColumn('leads.person_id', 'people.id')
                ->where('leads.pipeline_id', $criteria['pipeline_id'])
                ->when(isset($criteria['stage_id']), fn (QueryBuilder $q) => $q->where('leads.stage_id', $criteria['stage_id']))
                ->when(isset($criteria['lead_status']), fn (QueryBuilder $q) => $q->where('leads.status', $criteria['lead_status'])));
        }
        if (isset($criteria['interaction_kind'])) {
            $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('interactions')
                ->whereColumn('interactions.person_id', 'people.id')
                ->where('interactions.kind_code', $criteria['interaction_kind'])
                ->when(isset($criteria['interaction_days']), fn (QueryBuilder $q) => $q
                    ->where('interactions.occurred_at', '>=', now()->subDays((int) $criteria['interaction_days']))));
        }

        foreach ((array) ($criteria['custom'] ?? []) as $code => $value) {
            $fieldId = CustomField::query()->where('entity', CustomField::PERSON)->where('code', $code)->value('id');
            $query->whereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('custom_field_values')
                ->whereColumn('custom_field_values.entity_id', 'people.id')
                ->where('custom_field_values.custom_field_id', $fieldId ?? 0)
                ->where('custom_field_values.value', (string) $value));
        }

        return $query;
    }

    /**
     * Lives in the territory or below: by the card for people outside the structure, by the unit for its members.
     *
     * @param  Builder<Person>  $query
     */
    private function inTerritory(Builder $query, int $territoryId): void
    {
        $path = (string) Territory::query()->whereKey($territoryId)->value('path');
        $inside = fn (QueryBuilder $t) => $t->select('id')->from('territories')->where('path', 'like', ($path !== '' ? $path : '/none/').'%');

        $query->where(fn (Builder $w) => $w
            ->whereIn('people.territory_id', $inside)
            ->orWhereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('org_memberships as m')
                ->join('org_unit_territories as ut', 'ut.org_unit_id', '=', 'm.org_unit_id')
                ->whereColumn('m.person_id', 'people.id')
                ->whereIn('ut.territory_id', $inside)));
    }

    /**
     * @param  Builder<Person>  $query
     */
    private function inUnit(Builder $query, int $unitId): void
    {
        $path = (string) OrgUnit::query()->whereKey($unitId)->value('path');
        $inside = fn (QueryBuilder $u) => $u->select('id')->from('org_units')->where('path', 'like', ($path !== '' ? $path : '/none/').'%');

        $query->where(fn (Builder $w) => $w
            ->whereIn('people.responsible_unit_id', $inside)
            ->orWhereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('org_memberships as m')
                ->whereColumn('m.person_id', 'people.id')
                ->whereIn('m.org_unit_id', $inside)));
    }
}
