<?php

namespace App\Domain\Signatures\Support;

use RuntimeException;

/**
 * The platform's sealing key and certificate. Configured paths win; otherwise a self-signed
 * certificate is created once under storage/app/private/seal (for development and testing).
 */
class SealCertificate
{
    /**
     * @return array{0: string, 1: string} key path, certificate path
     */
    public function paths(): array
    {
        $key = config('dealer.signatures.seal_key');
        $cert = config('dealer.signatures.seal_cert');

        if (filled($key) && filled($cert)) {
            return [(string) $key, (string) $cert];
        }

        $dir = storage_path('app/private/seal');
        $key = $dir.'/seal.key';
        $cert = $dir.'/seal.crt';

        if (! is_file($key) || ! is_file($cert)) {
            $this->createSelfSigned($dir, $key, $cert);
        }

        return [$key, $cert];
    }

    public function subject(): string
    {
        $info = openssl_x509_parse((string) file_get_contents($this->paths()[1]));

        return is_array($info) ? (string) ($info['name'] ?? '') : '';
    }

    public function fingerprint(): string
    {
        return (string) openssl_x509_fingerprint((string) file_get_contents($this->paths()[1]), 'sha256');
    }

    private function createSelfSigned(string $dir, string $keyPath, string $certPath): void
    {
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new RuntimeException('Cannot create the seal key: '.openssl_error_string());
        }

        $dn = ['commonName' => config('app.name').' document seal (self-signed, testing only)', 'organizationName' => (string) config('app.name'), 'countryName' => 'CH'];
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);

        if ($csr === false || $csr === true) {
            throw new RuntimeException('Cannot create the seal certificate request.');
        }

        $x509 = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);

        if ($x509 === false) {
            throw new RuntimeException('Cannot sign the seal certificate.');
        }

        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($x509, $certPem);
        file_put_contents($keyPath, $keyPem);
        chmod($keyPath, 0600);
        file_put_contents($certPath, $certPem);
    }
}
