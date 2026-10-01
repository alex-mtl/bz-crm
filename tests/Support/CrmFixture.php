<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Access\Actions\AssignRole;
use App\Domain\Access\AuthorizationService;
use App\Domain\Access\Models\Role;
use App\Domain\Access\Scopes\ScopeType;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\CRM\Actions\ManagePipelines;
use App\Domain\CRM\Models\Pipeline;
use App\Domain\CRM\Models\PipelineStage;
use App\Domain\Geo\Models\Territory;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\OrgUnit;
use App\Domain\People\Actions\ManagePeople;
use App\Domain\People\Models\Person;
use App\Domain\Tasks\TaskWorkflow;

/**
 * CRM on top of the small organization of OrgFixture: an inbox operator scoped to Chișinău, HR in the central
 * office, a pipeline "joining" (new → contacted → meeting → active [won] / lost [lost]).
 */
final class CrmFixture
{
    public OrgFixture $org;

    public User $operator;

    public User $hr;

    public Pipeline $pipeline;

    public static function build(): self
    {
        $f = new self;
        $f->org = OrgFixture::build();
        app(ImportReferenceCatalogs::class)();
        TaskWorkflow::ensureDefaults();

        $f->operator = $f->org->member($f->org->central);
        app(AssignRole::class)($f->org->admin, $f->operator, Role::query()->where('code', 'inbox_operator')->sole(),
            scope: ScopeType::Territory, scopeId: $f->org->chisinau->id);
        $f->hr = $f->org->member($f->org->central, ['hr' => ScopeType::Organization]);
        app(AuthorizationService::class)->forget();

        $f->pipeline = app(ManagePipelines::class)->save($f->org->admin, ['code' => 'joining', 'names' => ['ro' => 'Aderare']], 'ro', [
            ['code' => 'new', 'names' => ['ro' => 'Nou']],
            ['code' => 'contacted', 'names' => ['ro' => 'Contactat']],
            ['code' => 'meeting', 'names' => ['ro' => 'Întâlnire']],
            ['code' => 'active', 'names' => ['ro' => 'Membru activ'], 'kind' => PipelineStage::WON],
            ['code' => 'lost', 'names' => ['ro' => 'Pierdut'], 'kind' => PipelineStage::LOST],
        ]);

        return $f;
    }

    public function stage(string $code): PipelineStage
    {
        return $this->pipeline->stages()->where('code', $code)->sole();
    }

    /**
     * A supporter outside the org structure, created by the super admin.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function supporter(?Territory $territory = null, ?OrgUnit $unit = null, array $attributes = []): Person
    {
        static $n = 0;
        $n++;

        return app(ManagePeople::class)->create($this->org->admin, [
            'first_name' => 'Susținător'.$n,
            'last_name' => 'Test'.$n,
            'person_type' => 'supporter',
            'territory_id' => $territory?->id,
            'responsible_unit_id' => $unit?->id,
            ...$attributes,
        ]);
    }
}
