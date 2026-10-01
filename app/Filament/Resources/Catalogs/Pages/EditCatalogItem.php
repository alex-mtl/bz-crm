<?php

declare(strict_types=1);

namespace App\Filament\Resources\Catalogs\Pages;

use App\Domain\Catalogs\Actions\UpdateCatalogItem;
use App\Domain\Catalogs\Models\CatalogItem;
use App\Domain\Identity\Models\User;
use App\Filament\Resources\Catalogs\CatalogItemResource;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditCatalogItem extends EditRecord
{
    protected static string $resource = CatalogItemResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['properties'] = [(string) $data['catalog_code'] => (array) ($data['properties'] ?? [])];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        assert($record instanceof CatalogItem);
        $actor = Filament::auth()->user();
        assert($actor instanceof User);

        try {
            return app(UpdateCatalogItem::class)(
                $actor, $record,
                ['ro' => $data['name_ro'] ?? null, 'ru' => $data['name_ru'] ?? null, 'en' => $data['name_en'] ?? null],
                isset($data['properties'][$record->catalog_code]) ? (array) $data['properties'][$record->catalog_code] : null,
            );
        } catch (DomainException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
