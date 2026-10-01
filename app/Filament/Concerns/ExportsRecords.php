<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Access\AuthorizationService;
use App\Domain\CRM\Exports\Exports;
use App\Domain\CRM\Models\ExportBatch;
use App\Domain\Identity\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * An "Export" header action for list pages (ТЗ §68): shown only with the explicit right; a small export is
 * downloaded at once, a large one is built in the queue and announced in the notifications.
 */
trait ExportsRecords
{
    /**
     * @param  callable(): array<string, mixed>  $filters  what is exported (the same filters the list shows)
     */
    protected function exportAction(string $kind, string $permission, callable $filters): Action
    {
        return Action::make('export')->label(__('admin.export.action'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
            ->visible(function () use ($permission): bool {
                $user = Filament::auth()->user();

                return $user instanceof User && app(AuthorizationService::class)->can($user, $permission);
            })
            ->modalDescription(__('admin.export.hint'))
            ->schema([
                Select::make('format')->label(__('admin.export.format'))->options(['xlsx' => 'Excel (XLSX)', 'csv' => 'CSV'])->default('xlsx')->required(),
            ])
            ->action(function (array $data) use ($kind, $filters) {
                $user = Filament::auth()->user();
                assert($user instanceof User);

                try {
                    $batch = app(Exports::class)->request($user, $kind, (string) $data['format'], $filters());
                } catch (AuthorizationException|DomainException $e) {
                    Notification::make()->title($e->getMessage() !== '' ? $e->getMessage() : __('access.denied'))->danger()->send();

                    return null;
                }

                if ($batch->status === ExportBatch::READY) {
                    return redirect()->route('exports.download', $batch);
                }
                Notification::make()->title($batch->status === ExportBatch::QUEUED ? __('admin.export.queued') : __('admin.export.failed'))
                    ->color($batch->status === ExportBatch::QUEUED ? 'info' : 'danger')->send();

                return null;
            });
    }
}
