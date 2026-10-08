<?php

namespace App\Domain\Documents\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Text from scans and photos with Tesseract (deu+fra+ita+eng, whatever is installed);
 * text PDFs are read directly with pdftotext, image-only PDFs are rendered page by page.
 */
class Ocr
{
    /** A PDF with less text than this per page is treated as a scan. */
    public const MIN_TEXT_PER_PAGE = 40;

    /**
     * @return array{text: string, pages: int|null}
     */
    public function extract(string $path, string $mime): array
    {
        if ($mime === 'application/pdf') {
            return $this->fromPdf($path);
        }

        return ['text' => $this->tesseract($path), 'pages' => 1];
    }

    /**
     * @return array{text: string, pages: int|null}
     */
    private function fromPdf(string $path): array
    {
        $pages = $this->pageCount($path);
        $text = $this->run(['pdftotext', '-layout', $path, '-']);

        if (mb_strlen(trim($text)) >= self::MIN_TEXT_PER_PAGE * max(1, $pages ?? 1)) {
            return ['text' => $text, 'pages' => $pages];
        }

        $dir = sys_get_temp_dir().'/ocr-'.bin2hex(random_bytes(6));
        mkdir($dir);

        try {
            $this->run(['pdftoppm', '-r', '300', '-png', $path, $dir.'/page']);
            $images = glob($dir.'/page*.png') ?: [];
            sort($images);

            $scanned = array_map(fn (string $image): string => $this->tesseract($image), $images);

            return ['text' => implode("\n\f", $scanned), 'pages' => $pages ?? count($images)];
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    private function tesseract(string $image): string
    {
        return $this->run(['tesseract', $image, 'stdout', '-l', $this->languages()]);
    }

    private function pageCount(string $path): ?int
    {
        $info = $this->run(['pdfinfo', $path], failOnError: false);

        return preg_match('/^Pages:\s+(\d+)/m', $info, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * The configured languages that Tesseract actually has (at least English).
     */
    public function languages(): string
    {
        $wanted = explode('+', (string) config('dealer.documents.ocr_languages'));
        $installed = preg_split('/\s+/', trim($this->run(['tesseract', '--list-langs'], failOnError: false))) ?: [];
        $available = array_values(array_intersect($wanted, $installed));

        return $available === [] ? 'eng' : implode('+', $available);
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, bool $failOnError = true): string
    {
        $result = Process::timeout(300)->run($command);

        if ($failOnError && ! $result->successful()) {
            throw new RuntimeException(trim($command[0].': '.$result->errorOutput()));
        }

        return $result->output();
    }
}
