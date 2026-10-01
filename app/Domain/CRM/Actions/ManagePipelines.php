<?php

declare(strict_types=1);

namespace App\Domain\CRM\Actions;

use App\Domain\Access\AuthorizationService;
use App\Domain\Audit\EventJournal;
use App\Domain\CRM\Exceptions\CrmRuleViolation;
use App\Domain\CRM\Models\Lead;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Models\PipelineStage;
use App\Domain\Identity\Models\User;
use App\Support\Translation\TranslatedNames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The pipeline constructor (ФО §6.9.2): pipelines and their stages are data, changed without code.
 */
final readonly class ManagePipelines
{
    public function __construct(private AuthorizationService $authorization, private EventJournal $journal) {}

    /**
     * @param  array{code?: string, names: array<string, string|null>, description?: string|null, is_active?: bool, sort_order?: int,
     *               auto_enroll_types?: list<string>|null, auto_assign_by_territory?: bool}  $data
     * @param  list<array{id?: int|null, code?: string, names: array<string, string|null>, kind?: string, is_active?: bool, sla_hours?: int|null}>|null  $stages
     *                                                                                                                                                            in order; null = leave stages as they are
     */
    public function save(User $actor, array $data, string $sourceLocale, ?array $stages = null, ?Pipeline $pipeline = null): Pipeline
    {
        $this->authorization->authorize($actor, 'pipelines.manage');

        $names = TranslatedNames::complete($data['names'], $sourceLocale)->names;
        $code = $pipeline !== null ? $pipeline->code : Str::slug((string) ($data['code'] ?? $names[$sourceLocale]), '_');
        if ($code === '' || ($pipeline === null && Pipeline::query()->where('code', $code)->exists())) {
            throw CrmRuleViolation::because('pipeline_code_taken');
        }
        if ($pipeline === null && ($stages === null || $stages === [])) {
            throw CrmRuleViolation::because('pipeline_needs_stages');
        }

        return DB::transaction(function () use ($pipeline, $code, $names, $data, $stages, $sourceLocale): Pipeline {
            $created = $pipeline === null;
            $pipeline ??= new Pipeline(['code' => $code]);
            $pipeline->fill([
                'name_ro' => $names['ro'], 'name_ru' => $names['ru'], 'name_en' => $names['en'],
                'description' => $data['description'] ?? $pipeline->description,
                'is_active' => $data['is_active'] ?? $pipeline->is_active ?? true,
                'sort_order' => $data['sort_order'] ?? $pipeline->sort_order ?? 0,
                'auto_enroll_types' => array_key_exists('auto_enroll_types', $data)
                    ? (($data['auto_enroll_types'] ?? []) === [] ? null : array_values($data['auto_enroll_types']))
                    : $pipeline->auto_enroll_types,
                'auto_assign_by_territory' => $data['auto_assign_by_territory'] ?? $pipeline->auto_assign_by_territory ?? false,
            ])->save();

            if ($stages !== null) {
                $this->syncStages($pipeline, $stages, $sourceLocale);
            }
            if (! $pipeline->stages()->where('is_active', true)->where('kind', PipelineStage::OPEN)->exists()) {
                throw CrmRuleViolation::because('pipeline_needs_open_stage');
            }

            $this->journal->record($created ? 'crm.pipeline.created' : 'crm.pipeline.updated', $pipeline, [], [
                'code' => $pipeline->code,
                'stages' => $pipeline->stages()->pluck('code')->all(),
            ]);

            return $pipeline;
        });
    }

    /**
     * @param  list<array{id?: int|null, code?: string, names: array<string, string|null>, kind?: string, is_active?: bool, sla_hours?: int|null}>  $stages
     */
    private function syncStages(Pipeline $pipeline, array $stages, string $sourceLocale): void
    {
        $kept = [];
        foreach ($stages as $index => $data) {
            $names = TranslatedNames::complete($data['names'], $sourceLocale)->names;
            $kind = $data['kind'] ?? PipelineStage::OPEN;
            if (! in_array($kind, [PipelineStage::OPEN, PipelineStage::WON, PipelineStage::LOST], true)) {
                throw CrmRuleViolation::because('invalid_stage_kind');
            }
            $stage = isset($data['id']) ? $pipeline->stages()->whereKey($data['id'])->firstOrFail() : new PipelineStage([
                'pipeline_id' => $pipeline->id,
                'code' => $this->freeStageCode($pipeline, Str::slug((string) ($data['code'] ?? $names[$sourceLocale]), '_')),
            ]);
            $stage->fill([
                'name_ro' => $names['ro'], 'name_ru' => $names['ru'], 'name_en' => $names['en'],
                'kind' => $kind, 'sort_order' => ($index + 1) * 10, 'is_active' => $data['is_active'] ?? true,
                // A deadline makes sense only where work is going on.
                'sla_hours' => $kind === PipelineStage::OPEN && (int) ($data['sla_hours'] ?? 0) > 0 ? (int) $data['sla_hours'] : null,
            ])->save();
            $kept[] = $stage->id;
        }

        // A stage that still holds leads is switched off, not removed: the history of those leads refers to it.
        foreach ($pipeline->stages()->whereNotIn('id', $kept)->get() as $removed) {
            $inUse = Lead::query()->where('stage_id', $removed->id)->exists()
                || DB::table('lead_stage_history')->where('from_stage_id', $removed->id)->orWhere('to_stage_id', $removed->id)->exists();
            $inUse ? $removed->update(['is_active' => false, 'sort_order' => 9000 + $removed->id]) : $removed->delete();
        }
    }

    private function freeStageCode(Pipeline $pipeline, string $base): string
    {
        $base = $base !== '' ? $base : 'stage';
        $code = $base;
        for ($i = 2; $pipeline->stages()->where('code', $code)->exists(); $i++) {
            $code = $base.'_'.$i;
        }

        return $code;
    }
}
