<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\CRM\Exports\Exports;
use App\Domain\CRM\Imports\PeopleImport;
use App\Domain\CRM\Models\ExportBatch;
use App\Domain\CRM\Models\ImportBatch;
use App\Domain\Identity\Models\User;
use App\Support\Spreadsheet\Spreadsheet;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * File downloads of the CRM (ТЗ §68): a finished export (only for the one who asked for it), the error report
 * of an import, the import template. The domain services decide who may get what.
 */
final class ExportController
{
    public function download(Request $request, ExportBatch $batch, Exports $exports): BinaryFileResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return response()->download(
            $exports->file($user, $batch),
            $batch->kind.'-'.$batch->created_at->format('Ymd-His').'.'.$batch->format,
        );
    }

    public function importReport(Request $request, ImportBatch $batch, PeopleImport $import): BinaryFileResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        [$header, $rows] = $import->report($user, $batch);

        return $this->spreadsheet('import-'.$batch->id.'-report.xlsx', $header, $rows);
    }

    public function importTemplate(PeopleImport $import): BinaryFileResponse
    {
        [$header, $rows] = $import->template();

        return $this->spreadsheet('people-import-template.xlsx', $header, $rows);
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<bool|float|int|string|null>>  $rows
     */
    private function spreadsheet(string $name, array $header, array $rows): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'bz').'.xlsx';
        Spreadsheet::write($path, 'xlsx', $header, $rows);

        return response()->download($path, $name)->deleteFileAfterSend();
    }
}
