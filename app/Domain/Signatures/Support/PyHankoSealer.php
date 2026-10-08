<?php

namespace App\Domain\Signatures\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * PAdES seal with the platform certificate through the pyHanko command line tool (installed
 * in the Docker image), plus an RFC 3161 timestamp when a timestamp service is configured.
 */
class PyHankoSealer implements DocumentSealer
{
    public function __construct(private readonly SealCertificate $certificate) {}

    public function seal(string $path): ?array
    {
        $binary = (string) config('dealer.signatures.pyhanko');

        if (! $this->isAvailable($binary)) {
            Log::warning('Signed PDF not sealed: pyHanko is not installed.', ['binary' => $binary]);

            return null;
        }

        [$key, $cert] = $this->certificate->paths();
        $tsa = config('dealer.signatures.tsa_url');
        $sealed = $path.'.sealed.pdf';

        $command = [$binary, 'sign', 'addsig', '--field', 'Seal'];

        if (filled($tsa)) {
            array_push($command, '--timestamp-url', (string) $tsa);
        }

        array_push($command, 'pemder', '--key', $key, '--cert', $cert, '--no-pass', $path, $sealed);

        $result = Process::timeout(120)->run($command);

        if (! $result->successful() || ! is_file($sealed)) {
            throw new RuntimeException('Sealing the PDF failed: '.trim($result->errorOutput()));
        }

        rename($sealed, $path);

        return [
            'method' => 'PAdES',
            'certificate' => $this->certificate->subject(),
            'certificate_sha256' => $this->certificate->fingerprint(),
            'timestamp_url' => filled($tsa) ? (string) $tsa : null,
            'sealed_at' => now()->toIso8601String(),
        ];
    }

    private function isAvailable(string $binary): bool
    {
        return Process::run([$binary, '--version'])->successful();
    }
}
