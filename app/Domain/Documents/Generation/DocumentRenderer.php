<?php

namespace App\Domain\Documents\Generation;

use App\Domain\Documents\Enums\TemplateType;
use App\Domain\Documents\Support\PdfRenderer;
use App\Support\BusinessRuleException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns a stored snapshot into HTML (preview) or PDF (Gotenberg), in the document's language.
 * Uses nothing but the snapshot, so the same snapshot always gives the same document.
 */
class DocumentRenderer
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function html(array $snapshot): string
    {
        $type = TemplateType::from((string) $snapshot['type']);
        $previous = app()->getLocale();
        app()->setLocale((string) $snapshot['locale']);

        try {
            return view($type->view(), ['d' => $snapshot, 'logo' => self::logoDataUri($snapshot['company']['logo_path'] ?? null)])->render();
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function pdf(array $snapshot): string
    {
        if (! $this->pdf->isAvailable()) {
            throw new BusinessRuleException(__('PDFs cannot be created: the PDF service (Gotenberg) is not configured.'));
        }

        try {
            return $this->pdf->fromHtml($this->html($snapshot));
        } catch (Throwable $e) {
            report($e);

            throw new BusinessRuleException(__('The PDF could not be created. Is the PDF service (Gotenberg) running?'));
        }
    }

    /**
     * The dealer's logo as a data URI, so the PDF service needs no access to our storage.
     */
    public static function logoDataUri(mixed $path): ?string
    {
        $disk = Storage::disk((string) config('dealer.documents.disk'));

        if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
            return null;
        }

        $mime = $disk->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
    }
}
