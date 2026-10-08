<?php

namespace App\Domain\Import\Importers;

use App\Domain\Documents\Actions\StoreDocument;
use App\Domain\Documents\Enums\DocumentSource;
use App\Domain\Documents\Models\DocumentCategory;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Import\Enums\ImportRowAction;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Support\RowResult;
use App\Domain\Vehicles\Models\StockCycle;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Support\Stammnummer;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * A dealer's document folders as one ZIP, e.g. {year}/{Stammnummer}_{Name}/{01_Ankauf}/{file}
 * with file names like 2026-07-14_683737537_Kaufvertrag_D0028.pdf.
 *
 * - The file-name pattern is configurable ({date}, {stammnummer}, {type}, {id}).
 * - The folder number (01–06) and the type pick the category; the vehicle comes from the
 *   Stammnummer in the file or folder name; the file is linked to the cycle owning it at that date.
 * - Exact duplicates are skipped; files without a vehicle go to the "not assigned" inbox.
 * - The ZIP is read entry by entry, so a 1 GB folder never has to be unpacked at once.
 */
class DocumentFolderImporter implements Importer
{
    public const DEFAULT_PATTERN = '{date}_{stammnummer}_{type}_{id}';

    /** Keyword in the type (lower case) → category key; "@04" restricts it to one folder. */
    public const TYPE_KEYWORDS = [
        'kaufvertrag@01' => 'purchase_contract',
        'kaufvertrag@04' => 'sales_contract',
        'verkaufsvertrag' => 'sales_contract',
        'rechnung@01' => 'supplier_invoice',
        'rechnung@03' => 'workshop_invoice',
        'rechnung@04' => 'invoice',
        'quittung' => 'receipt',
        'zahlung' => 'payment_proof',
        'ausweis@01' => 'seller_identity',
        'ausweis@04' => 'buyer_identity',
        'fahrzeugausweis' => 'registration',
        'coc' => 'coc',
        'mfk' => 'inspection_report',
        'service' => 'service_history',
        'foto' => 'photo',
        'bild' => 'photo',
        'transport' => 'transport_invoice',
        'offerte' => 'offer',
        'übergabe' => 'handover_protocol',
        'garantie' => 'warranty_policy',
        'leasing' => 'leasing_contract',
        'budget' => 'budget_calculation',
        'auszahlung' => 'payout_confirmation',
    ];

    public const FOLDER_FALLBACK = [
        '01' => 'payment_proof',
        '02' => 'vehicle_other',
        '03' => 'workshop_invoice',
        '04' => 'correspondence',
        '05' => 'warranty_policy',
        '06' => 'financing_checklist',
    ];

    private ?ZipArchive $zip = null;

    public function __construct(private readonly StoreDocument $store) {}

    public function fields(): array
    {
        return [];
    }

    public function guesses(): array
    {
        return [];
    }

    public function rows(ImportRun $run, string $localPath): iterable
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($localPath) !== true) {
            throw new RuntimeException('Not a ZIP file.');
        }

        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);

            if (str_ends_with($name, '/') || str_contains($name, '__MACOSX') || str_starts_with(basename($name), '.')) {
                continue;
            }

            yield $name => ['entry' => $name, ...$this->parse($name, (string) $run->option('pattern', self::DEFAULT_PATTERN))];
        }
    }

    public function importRow(ImportRun $run, RowResult $result, bool $dryRun): void
    {
        $p = $result->payload;
        $category = $this->category($p);
        $vehicle = $p['stammnummer'] === null ? null : Vehicle::query()->where('stammnummer', $p['stammnummer'])->first();
        $cycle = $vehicle === null ? null : $this->cycleAt($vehicle, $p['date']);

        $temp = $this->extract((string) $p['entry']);

        try {
            $existing = DocumentVersion::query()->with('document')->where('sha256', hash_file('sha256', $temp))->first();

            if ($existing !== null) {
                $result->action(ImportRowAction::Skip, $existing->document)->note(__('Exact duplicate of ":title".', ['title' => $existing->document->title]));

                return;
            }

            if ($cycle === null) {
                $result->note($p['stammnummer'] === null
                    ? __('No Stammnummer in the name; goes to the inbox.')
                    : __('No vehicle with Stammnummer :number; goes to the inbox.', ['number' => Stammnummer::format($p['stammnummer'])]));
            }

            $result->note(__('Category: :category', ['category' => $category->name]));

            if ($dryRun) {
                $result->action($cycle === null ? ImportRowAction::Unassigned : ImportRowAction::Create);

                return;
            }

            $document = ($this->store)($temp, $category, [
                'document_on' => $p['date'],
                'source' => DocumentSource::Import,
                'legacy_ref' => $p['id'],
                'original_name' => basename((string) $p['entry']),
                'title' => trim($category->name.' '.($p['type'] ?? '')).($p['date'] ? ' '.date('d.m.Y', (int) strtotime($p['date'])) : ''),
            ], $cycle === null ? [] : [$cycle]);

            $result->created($document);

            if ($document->possible_duplicate_of_id !== null) {
                $result->note(__('Possible duplicate of another document in this file; merge it there.'));
            }

            $result->action($cycle === null ? ImportRowAction::Unassigned : ImportRowAction::Create, $document);
        } finally {
            @unlink($temp);
        }
    }

    public function afterCommit(ImportRun $run): void {}

    /**
     * @return array{date: string|null, stammnummer: string|null, type: string|null, id: string|null, folder: string|null}
     */
    public function parse(string $entry, string $pattern): array
    {
        $file = pathinfo($entry, PATHINFO_FILENAME);
        $segments = explode('/', $entry);

        $regex = '/^'.preg_replace_callback('/\{(\w+)\}|([^{]+)/', fn (array $m): string => $m[1] !== ''
            ? match ($m[1]) {
                'date' => '(?<date>\d{4}-\d{2}-\d{2}|UNDATIERT|undatiert)',
                'stammnummer' => '(?<stammnummer>[\d.]{9,15})',
                'id' => '(?<id>[A-Za-z]?\d+)',
                default => '(?<'.$m[1].'>.+?)',
            }
            : preg_quote($m[2], '/'), $pattern).'$/u';

        $parsed = preg_match($regex, $file, $m) === 1 ? $m : [];

        $stammnummer = Stammnummer::normalize($parsed['stammnummer'] ?? null);

        // Fall back to the vehicle folder name "{Stammnummer}_{Name}".
        if ($stammnummer === null || ! Stammnummer::isValid($stammnummer)) {
            $stammnummer = null;

            foreach ($segments as $segment) {
                if (preg_match('/^([\d.]{9,15})_/', $segment, $s) === 1 && Stammnummer::isValid($s[1])) {
                    $stammnummer = Stammnummer::normalize($s[1]);
                }
            }
        }

        $folder = null;

        foreach ($segments as $segment) {
            if (preg_match('/^(0[1-6])_/', $segment, $f) === 1) {
                $folder = $f[1];
            }
        }

        $date = $parsed['date'] ?? null;

        return [
            'date' => $date === null || strcasecmp($date, 'UNDATIERT') === 0 ? null : $date,
            'stammnummer' => $stammnummer,
            'type' => isset($parsed['type']) ? str_replace(['-', '_'], ' ', $parsed['type']) : null,
            'id' => $parsed['id'] ?? null,
            'folder' => $folder,
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function category(array $p): DocumentCategory
    {
        $type = mb_strtolower((string) ($p['type'] ?? ''));
        $folder = $p['folder'] ?? null;
        $key = null;

        foreach (self::TYPE_KEYWORDS as $keyword => $category) {
            [$word, $onlyFolder] = array_pad(explode('@', $keyword), 2, null);

            if ($type !== '' && str_contains($type, $word) && ($onlyFolder === null || $onlyFolder === $folder)) {
                $key = $category;

                break;
            }
        }

        $key ??= self::FOLDER_FALLBACK[$folder ?? '02'] ?? 'vehicle_other';

        return DocumentCategory::query()->where('key', $key)->firstOrFail();
    }

    /**
     * The file that owned the car at that date (latest purchase on or before it), else the latest.
     */
    private function cycleAt(Vehicle $vehicle, ?string $date): ?StockCycle
    {
        $cycles = StockCycle::query()->where('vehicle_id', $vehicle->getKey())->orderByDesc('purchased_on')->get();

        if ($date !== null) {
            $owning = $cycles->first(fn (StockCycle $cycle): bool => $cycle->purchased_on !== null && $cycle->purchased_on->toDateString() <= $date);

            if ($owning !== null) {
                return $owning;
            }
        }

        return $cycles->first();
    }

    private function extract(string $entry): string
    {
        if ($this->zip === null) {
            throw new RuntimeException('ZIP not open.');
        }

        $stream = $this->zip->getStream($entry);

        if ($stream === false) {
            throw new RuntimeException("Cannot read {$entry}.");
        }

        $temp = tempnam(sys_get_temp_dir(), 'zip').'.'.Str::lower(pathinfo($entry, PATHINFO_EXTENSION));
        file_put_contents($temp, $stream);
        fclose($stream);

        return $temp;
    }
}
