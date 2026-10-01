<?php

declare(strict_types=1);

namespace App\Filament\Resources\Imports\Pages;

use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\Imports\PeopleImport;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgStructure;
use App\Filament\Resources\Imports\ImportBatchResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\Options;
use App\Filament\Support\Places;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

class ListImportBatches extends ListRecords
{
    protected static string $resource = ImportBatchResource::class;

    protected function getHeaderActions(): array
    {
        $i = fn (string $key): string => __('admin.imports.'.$key);

        return [
            Action::make('template')->label($i('template'))->icon(Heroicon::OutlinedDocumentArrowDown)->color('gray')
                ->url(route('imports.template')),
            Action::make('upload')->label($i('upload'))->icon(Heroicon::OutlinedArrowUpTray)
                ->modalDescription($i('upload_hint'))
                ->schema([
                    FileUpload::make('file')->label($i('file'))->required()->disk('local')->directory('imports/incoming')
                        ->storeFileNamesIn('original_name')->maxSize(20480)
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                    Select::make('person_type')->label($i('default_type'))->options(fn (): array => Options::catalog('person_types'))->default('supporter')->required(),
                    Places::unit('responsible_unit_id')->label(__('admin.people.responsible_unit'))
                        ->default(fn (): ?int => $this->actor() !== null ? app(OrgStructure::class)->unitOf($this->actor()->person_id)?->id : null),
                    Places::territory()->label($i('default_territory')),
                    Select::make('duplicates')->label($i('on_duplicates'))->required()->default('skip')
                        ->options(['skip' => $i('duplicates_skip'), 'create' => $i('duplicates_create')]),
                    Select::make('pipeline_id')->label($i('create_leads'))->options(fn (): array => LeadResource::pipelines())
                        ->visible(fn (): bool => $this->actor() !== null && app(AuthorizationService::class)->can($this->actor(), 'crm.import')
                            && app(AuthorizationService::class)->can($this->actor(), 'leads.create')),
                ])
                ->action(function (array $data) {
                    $actor = $this->actor();
                    assert($actor instanceof User);
                    $stored = (string) $data['file'];

                    try {
                        $batch = app(PeopleImport::class)->upload($actor, Storage::disk('local')->path($stored), (string) ($data['original_name'] ?? basename($stored)), [
                            'person_type' => $data['person_type'] ?? null,
                            'responsible_unit_id' => isset($data['responsible_unit_id']) ? (int) $data['responsible_unit_id'] : null,
                            'territory_id' => isset($data['territory_id']) ? (int) $data['territory_id'] : null,
                            'duplicates' => (string) ($data['duplicates'] ?? 'skip'),
                            'pipeline_id' => isset($data['pipeline_id']) ? (int) $data['pipeline_id'] : null,
                        ]);
                    } catch (AuthorizationException|DomainException $e) {
                        Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

                        return null;
                    } finally {
                        Storage::disk('local')->delete($stored);
                    }

                    return redirect(ImportBatchResource::getUrl('view', ['record' => $batch]));
                }),
        ];
    }

    private function actor(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }
}
