<?php

namespace App\Domain\Import\Support;

use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Reads .xlsx/.xlsm and .csv files: sheet names, header row, and rows keyed by header.
 * CSV delimiter is detected (Swiss Excel writes ";").
 */
class SpreadsheetReader
{
    public function __construct(private readonly string $path, private readonly string $fileName) {}

    /**
     * @return list<string>
     */
    public function sheetNames(): array
    {
        $reader = $this->open();
        $names = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }
        } finally {
            $reader->close();
        }

        return $names;
    }

    /**
     * Column headers of the first non-empty row.
     *
     * @return list<string>
     */
    public function headers(?string $sheet = null): array
    {
        foreach ($this->rawRows($sheet) as $row) {
            return array_values(array_filter(array_map(fn (mixed $cell): string => trim((string) self::scalar($cell)), $row), fn (string $h): bool => $h !== ''));
        }

        return [];
    }

    /**
     * Data rows keyed by header, with their row number in the file.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function rows(?string $sheet = null): Generator
    {
        $headers = null;
        $number = 0;

        foreach ($this->rawRows($sheet) as $row) {
            $number++;

            if ($headers === null) {
                $headers = array_map(fn (mixed $cell): string => trim((string) self::scalar($cell)), $row);

                continue;
            }

            $values = [];

            foreach ($headers as $i => $header) {
                if ($header !== '') {
                    $values[$header] = $row[$i] ?? null;
                }
            }

            if (array_filter($values, fn (mixed $v): bool => $v !== null && $v !== '') === []) {
                continue;
            }

            yield $number => $values;
        }
    }

    /**
     * @return Generator<int, list<mixed>>
     */
    private function rawRows(?string $sheetName): Generator
    {
        $reader = $this->open();

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheetName !== null && $sheetName !== '' && $sheet->getName() !== $sheetName) {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();

                    if (array_filter($cells, fn (mixed $v): bool => $v !== null && $v !== '') === []) {
                        continue;
                    }

                    yield $cells;
                }

                return;
            }
        } finally {
            $reader->close();
        }
    }

    private function open(): XlsxReader|CsvReader
    {
        $extension = strtolower(pathinfo($this->fileName, PATHINFO_EXTENSION));

        $reader = match ($extension) {
            'xlsx', 'xlsm' => new XlsxReader,
            'csv', 'txt' => new CsvReader($this->csvOptions()),
            default => throw new RuntimeException("Unsupported file type: .{$extension}"),
        };

        $reader->open($this->path);

        return $reader;
    }

    private function csvOptions(): CsvOptions
    {
        $options = new CsvOptions;
        $firstLine = (string) fgets(fopen($this->path, 'rb') ?: throw new RuntimeException('Cannot read file.'));
        $options->FIELD_DELIMITER = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        return $options;
    }

    private static function scalar(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }
}
