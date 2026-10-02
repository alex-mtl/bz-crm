<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Geo\CanvassSummary;
use App\Domain\Geo\FieldAccess;
use App\Domain\Geo\Models\House;
use App\Domain\Geo\Models\Territory;
use App\Filament\Concerns\ChecksPermissions;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * The summary of the canvass (ФО §6.11): by sector, by polling district, by house — «% обойдённых квартир,
 * % сторонников». Counted over the houses the reader's `geo.summary.read` reaches, and nothing beyond.
 */
class FieldSummary extends Page
{
    use ChecksPermissions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'field-summary';

    protected string $view = 'filament.pages.field-summary';

    #[Url]
    public ?int $territory = null;

    public static function canAccess(): bool
    {
        return static::allows('geo.summary.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.field');
    }

    public static function getNavigationLabel(): string
    {
        return __('geo.ui.summary');
    }

    public function getTitle(): string
    {
        return __('geo.ui.summary');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $summary = app(CanvassSummary::class);
        $within = $this->territory !== null ? Territory::query()->find($this->territory) : null;
        $rows = $summary->byTerritory(static::actor(), $within);

        // Down to the houses once a territory is chosen.
        $houses = [];
        if ($within !== null) {
            $query = app(FieldAccess::class)->houses(static::actor(), 'geo.summary.read')->active()
                ->whereIn('houses.territory_id', Territory::query()->withinPath($within->path)->select('id'));
            $figures = $summary->perHouse($query);
            $houses = (clone $query)->with('address.street')->orderBy('houses.id')->limit(300)->get()
                ->map(fn (House $house): array => ['house' => $house, 'figures' => $figures[$house->id] ?? null])->all();
        }

        return [
            'rows' => $rows,
            'within' => $within,
            'houses' => $houses,
            'statuses' => CatalogItem::query()->ofCatalog('canvass_statuses')->get(),
        ];
    }
}
