<?php

namespace App\Domain\Import\Actions;

use App\Domain\Import\Enums\ImporterType;
use App\Domain\Import\Models\ImportPreset;
use App\Domain\Import\Models\ImportRun;
use App\Domain\Import\Support\Normalize;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores the uploaded file under the dealer's prefix and creates the run (optionally saving
 * the column mapping as a preset). The check (dry run) is started separately.
 */
class CreateImportRun
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, string|null>  $mapping
     * @param  array<string, mixed>  $options
     */
    public function __invoke(string $localPath, string $fileName, ImporterType $importer, array $mapping = [], array $options = [], ?string $savePresetAs = null): ImportRun
    {
        $disk = (string) config('dealer.documents.disk');
        $path = sprintf('tenants/%s/imports/%s-%s', $this->context->id(), now()->format('Ymd-His'), Str::slug(pathinfo($fileName, PATHINFO_FILENAME)).'.'.strtolower(pathinfo($fileName, PATHINFO_EXTENSION)));

        $stream = fopen($localPath, 'rb');
        Storage::disk($disk)->put($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $preset = null;

        if (filled($savePresetAs)) {
            $preset = ImportPreset::query()->updateOrCreate(
                ['importer' => $importer, 'name' => trim((string) $savePresetAs)],
                ['mapping' => $mapping, 'options' => $options],
            );
        }

        return ImportRun::create([
            'importer' => $importer,
            'preset_id' => $preset?->getKey(),
            'file_name' => $fileName,
            'disk' => $disk,
            'path' => $path,
            'mapping' => $mapping,
            'options' => $options,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Maps each field to the header that matches one of its known names (case and accents ignored).
     *
     * @param  array<string, list<string>>  $guesses
     * @param  list<string>  $headers
     * @return array<string, string|null>
     */
    public static function guessMapping(array $guesses, array $headers): array
    {
        $byKey = [];

        foreach ($headers as $header) {
            $byKey[Normalize::nameKey($header)] ??= $header;
        }

        $mapping = [];

        foreach ($guesses as $field => $names) {
            $mapping[$field] = null;

            foreach ($names as $name) {
                if (isset($byKey[Normalize::nameKey($name)])) {
                    $mapping[$field] = $byKey[Normalize::nameKey($name)];

                    break;
                }
            }
        }

        return $mapping;
    }
}
