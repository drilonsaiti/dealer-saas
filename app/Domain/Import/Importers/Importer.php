<?php

namespace App\Domain\Import\Importers;

use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Support\RowResult;

/**
 * One kind of import. RunImport drives it; the importer only reads rows and writes one row.
 */
interface Importer
{
    /**
     * Fields a source column can be mapped to (empty for file imports like the ZIP).
     *
     * @return array<string, string> field => label
     */
    public function fields(): array;

    /**
     * Header names recognised automatically for each field (e.g. Aziri's "EK Datum").
     *
     * @return array<string, list<string>>
     */
    public function guesses(): array;

    /**
     * @return iterable<string, array<string, mixed>> row reference => payload
     */
    public function rows(ImportRun $run, string $localPath): iterable;

    /**
     * Writes one row. In a dry run, writes happen inside a transaction that is rolled back
     * afterwards, unless the importer has side effects outside the database (files): then
     * it must only plan when $dryRun is true.
     */
    public function importRow(ImportRun $run, RowResult $result, bool $dryRun): void;

    /**
     * After a committed import (e.g. continue the number ranges after the imported ones).
     */
    public function afterCommit(ImportRun $run): void;
}
