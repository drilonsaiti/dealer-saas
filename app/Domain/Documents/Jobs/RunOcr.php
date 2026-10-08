<?php

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Enums\OcrStatus;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Support\Ocr;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Makes a scan or photo searchable. Runs in the queue as the dealer that uploaded it
 * (the tenant travels with the job payload), so RLS applies here too.
 */
class RunOcr implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public function __construct(public readonly string $versionId) {}

    public function handle(Ocr $ocr): void
    {
        $version = DocumentVersion::query()->find($this->versionId);

        if ($version === null || $version->ocr_status !== OcrStatus::Pending) {
            return;
        }

        $local = tempnam(sys_get_temp_dir(), 'doc');

        try {
            file_put_contents($local, Storage::disk($version->disk)->readStream($version->path));
            $result = $ocr->extract($local, $version->mime);

            $version->forceFill([
                'ocr_text' => mb_substr(trim($result['text']), 0, 1_000_000),
                'page_count' => $result['pages'],
                'ocr_status' => OcrStatus::Done,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('OCR failed', ['version' => $version->getKey(), 'error' => $e->getMessage()]);
            $version->forceFill(['ocr_status' => OcrStatus::Failed])->save();
        } finally {
            if (is_string($local) && is_file($local)) {
                unlink($local);
            }
        }
    }
}
