<?php

namespace App\Domain\Documents\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTML → PDF through Gotenberg (headless Chromium), as for every generated document.
 */
class PdfRenderer
{
    public function isAvailable(): bool
    {
        return filled(config('dealer.gotenberg_url'));
    }

    /**
     * @param  bool  $cssPages  page size and margins come from the document's @page rules (invoices with a QR bill need a page without margins)
     */
    public function fromHtml(string $html, bool $cssPages = false): string
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('GOTENBERG_URL is not configured.');
        }

        $response = Http::timeout(60)
            ->attach('files', $html, 'index.html')
            ->post(rtrim((string) config('dealer.gotenberg_url'), '/').'/forms/chromium/convert/html', $cssPages ? [
                'paperWidth' => '8.27',
                'paperHeight' => '11.7',
                'marginTop' => '0',
                'marginBottom' => '0',
                'marginLeft' => '0',
                'marginRight' => '0',
                'preferCssPageSize' => 'true',
                'printBackground' => 'true',
            ] : [
                'paperWidth' => '8.27',
                'paperHeight' => '11.7',
                'marginTop' => '0.6',
                'marginBottom' => '0.6',
                'marginLeft' => '0.6',
                'marginRight' => '0.6',
                'printBackground' => 'true',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gotenberg failed with HTTP '.$response->status());
        }

        return $response->body();
    }
}
