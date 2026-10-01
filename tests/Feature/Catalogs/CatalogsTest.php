<?php

use App\Domain\Audit\Models\JournalEntry;
use App\Domain\Catalogs\Actions\CreateCatalogItem;
use App\Domain\Catalogs\Actions\ImportReferenceCatalogs;
use App\Domain\Catalogs\Actions\MergeCatalogItems;
use App\Domain\Catalogs\Actions\ProposeCatalogItem;
use App\Domain\Catalogs\Actions\ReviewCatalogProposal;
use App\Domain\Catalogs\Actions\SetCatalogItemActive;
use App\Domain\Catalogs\Actions\UpdateCatalogItem;
use App\Domain\Catalogs\CatalogRegistry;
use App\Domain\Catalogs\Enums\ProposalStatus;
use App\Domain\Catalogs\Events\CatalogItemsMerged;
use App\Domain\Catalogs\Exceptions\CatalogRuleViolation;
use App\Domain\Catalogs\Models\CatalogItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => app(ImportReferenceCatalogs::class)());

it('loads the starter task types and person types', function () {
    expect(CatalogItem::query()->ofCatalog('task_types')->count())->toBe(22)
        ->and(CatalogItem::query()->ofCatalog('person_types')->count())->toBe(10)
        ->and(CatalogItem::query()->ofCatalog('task_types')->where('code', 'assignment')->sole()->property('requires_review'))->toBeTrue()
        ->and(CatalogItem::query()->ofCatalog('task_types')->where('code', 'call')->sole()->property('requires_review'))->toBeFalse();
});

it('flags working translations of starter data for a native speaker', function () {
    expect(CatalogItem::query()->ofCatalog('task_types')->where('code', 'assignment')->sole()->unverified_locales)->toBe(['ro', 'en']);
});

it('imports reference data idempotently and never overwrites admin edits', function () {
    $item = CatalogItem::query()->ofCatalog('task_types')->where('code', 'call')->sole();
    $item->update(['name_ru' => 'Телефонный звонок']);

    expect(app(ImportReferenceCatalogs::class)())->toBe([])
        ->and($item->fresh()->name_ru)->toBe('Телефонный звонок')
        ->and(CatalogItem::query()->ofCatalog('task_types')->count())->toBe(22);
});

it('copies a name entered in one language to the other two and flags them (Д-16)', function () {
    $item = app(CreateCatalogItem::class)(userWithRoles('catalog_admin'), 'task_types', ['ru' => 'Дежурство'], 'ru', ['requires_review' => false, 'phase' => 2]);

    expect([$item->name_ro, $item->name_en])->toBe(['Дежурство', 'Дежурство'])
        ->and($item->unverified_locales)->toBe(['ro', 'en'])
        ->and($item->is_active)->toBeTrue();
});

it('marks a translation verified once an admin edits it', function () {
    $admin = userWithRoles('catalog_admin');
    $item = app(CreateCatalogItem::class)($admin, 'task_types', ['ru' => 'Дежурство'], 'ru');

    $item = app(UpdateCatalogItem::class)($admin, $item, ['ro' => 'Serviciu de gardă']);

    expect($item->name_ro)->toBe('Serviciu de gardă')
        ->and($item->unverified_locales)->toBe(['en']);
});

it('lets only catalog administrators edit catalogs directly', function () {
    app(CreateCatalogItem::class)(userWithRoles('unit_head'), 'task_types', ['ru' => 'Своё'], 'ru');
})->throws(AuthorizationException::class);

it('deactivates instead of deleting and refuses to deactivate system items', function () {
    $admin = userWithRoles('catalog_admin');
    $call = CatalogItem::query()->ofCatalog('task_types')->where('code', 'call')->sole();

    app(SetCatalogItemActive::class)($admin, $call, false);
    expect(CatalogItem::query()->ofCatalog('task_types')->selectable()->where('code', 'call')->exists())->toBeFalse()
        ->and(CatalogItem::query()->ofCatalog('task_types')->where('code', 'call')->exists())->toBeTrue();

    expect(fn () => app(SetCatalogItemActive::class)($admin, CatalogItem::query()->ofCatalog('task_types')->where('code', 'assignment')->sole(), false))
        ->toThrow(CatalogRuleViolation::class);
});

it('merges a duplicate into the item that stays and tells modules to move references', function () {
    Event::fake([CatalogItemsMerged::class]);
    $admin = userWithRoles('catalog_admin');
    $duplicate = app(CreateCatalogItem::class)($admin, 'task_types', ['ru' => 'Созвон'], 'ru');
    $call = CatalogItem::query()->ofCatalog('task_types')->where('code', 'call')->sole();

    app(MergeCatalogItems::class)($admin, $duplicate, $call);

    expect($duplicate->fresh()->is_active)->toBeFalse()
        ->and($duplicate->fresh()->merged_into_id)->toBe($call->id);
    Event::assertDispatched(CatalogItemsMerged::class, fn ($e) => $e->sourceCode === $duplicate->code && $e->targetCode === 'call');
});

it('sends a manager proposal to the queue instead of the catalog, with similar items attached', function () {
    $proposal = app(ProposeCatalogItem::class)(userWithRoles('unit_head'), 'task_types', ['ru' => 'Звонки'], 'ru', 'Нужен тип для обзвона сторонников');

    expect($proposal->status)->toBe(ProposalStatus::Pending)
        ->and($proposal->similar_item_ids)->toContain(CatalogItem::query()->ofCatalog('task_types')->where('code', 'call')->value('id'))
        ->and(CatalogItem::query()->where('name_ru', 'Звонки')->exists())->toBeFalse();
});

it('creates the item only after the catalog administrator approves the proposal', function () {
    $proposal = app(ProposeCatalogItem::class)(userWithRoles('unit_head'), 'task_types', ['ru' => 'Агитационный пикет'], 'ru', 'Проводим пикеты еженедельно');

    app(ReviewCatalogProposal::class)(userWithRoles('catalog_admin'), $proposal, true, null, ['ro' => 'Pichet de agitație']);

    $item = CatalogItem::query()->where('name_ru', 'Агитационный пикет')->sole();
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and($item->name_ro)->toBe('Pichet de agitație')
        ->and($item->unverified_locales)->toBe(['en'])
        ->and(JournalEntry::query()->where('event_type', 'catalogs.proposal.approved')->exists())->toBeTrue();
});

it('requires a reason to reject a proposal and a review right to decide', function () {
    $proposal = app(ProposeCatalogItem::class)(userWithRoles('unit_head'), 'task_types', ['ru' => 'Что-то'], 'ru', 'Нужно');

    expect(fn () => app(ReviewCatalogProposal::class)(userWithRoles('catalog_admin'), $proposal, false))->toThrow(CatalogRuleViolation::class)
        ->and(fn () => app(ReviewCatalogProposal::class)(userWithRoles('unit_head'), $proposal, true))->toThrow(AuthorizationException::class);

    app(ReviewCatalogProposal::class)(userWithRoles('catalog_admin'), $proposal, false, 'Дублирует «Прочее»');
    expect($proposal->fresh()->status)->toBe(ProposalStatus::Rejected);
});

it('has a ro, ru and en name for every registered catalog', function (string $locale) {
    $missing = collect(app(CatalogRegistry::class)->all())
        ->filter(fn ($definition) => trans($definition->labelKey(), [], $locale) === $definition->labelKey())
        ->keys()->all();

    expect($missing)->toBe([]);
})->with(['ro', 'ru', 'en']);
