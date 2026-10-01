<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvReaderOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\CSV\Options as CsvWriterOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Reading and writing CSV and XLSX (ТЗ §68) as plain rows of strings, row by row — files never have to fit
 * in memory. The only place that knows the spreadsheet library (ADR-009).
 */
final class Spreadsheet
{
    public const array FORMATS = ['csv', 'xlsx'];

    /**
     * Rows of the first sheet as lists of trimmed strings (empty cells = null).
     *
     * @return Generator<int, list<string|null>>
     */
    public static function read(string $path, string $format): Generator
    {
        $reader = match ($format) {
            'csv' => new CsvReader(self::csvReaderOptions($path)),
            'xlsx' => new XlsxReader,
            default => throw new InvalidArgumentException("Unsupported format [{$format}]."),
        };
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield array_map(self::cellToString(...), $row->toArray());
                }
                break;   // the first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<list<bool|float|int|string|null>>  $rows
     * @return int rows written, without the header
     */
    public static function write(string $path, string $format, array $header, iterable $rows): int
    {
        $writer = match ($format) {
            // A BOM and ";" make the file open correctly in Excel with Romanian and Russian text.
            'csv' => new CsvWriter(self::csvWriterOptions()),
            'xlsx' => new XlsxWriter,
            default => throw new InvalidArgumentException("Unsupported format [{$format}]."),
        };
        $writer->openToFile($path);

        try {
            $writer->addRow(Row::fromValues($header));
            $count = 0;
            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues(array_map(self::safeCell(...), $row)));
                $count++;
            }

            return $count;
        } finally {
            $writer->close();
        }
    }

    private static function cellToString(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        $string = trim((string) (is_scalar($value) ? $value : ''));

        return $string === '' ? null : $string;
    }

    /**
     * A text cell starting with = + - @ would be run as a formula by spreadsheet programs — it is written as text.
     */
    private static function safeCell(bool|float|int|string|null $value): bool|float|int|string|null
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    private static function csvReaderOptions(string $path): CsvReaderOptions
    {
        $options = new CsvReaderOptions;
        $options->FIELD_DELIMITER = self::csvDelimiter($path);

        return $options;
    }

    private static function csvWriterOptions(): CsvWriterOptions
    {
        $options = new CsvWriterOptions;
        $options->FIELD_DELIMITER = ';';
        $options->SHOULD_ADD_BOM = true;

        return $options;
    }

    private static function csvDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $line = $handle !== false ? (string) fgets($handle) : '';
        if ($handle !== false) {
            fclose($handle);
        }
        $counts = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
