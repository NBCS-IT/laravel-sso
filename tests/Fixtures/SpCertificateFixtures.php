<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use Illuminate\Support\Facades\Storage;
use NBCSIT\Sso\Certificates\SpCertificateGenerator;

/**
 * Real service provider keypairs, for tests that need one on a disk.
 *
 * These are generated rather than hard-coded, because a private key checked
 * into a repository is a private key somebody eventually copies — and because
 * half of what is under test is whether a certificate and a key actually belong
 * to each other, which no hard-coded pair can prove once it has been edited.
 *
 * RSA-2048 costs a couple of hundred milliseconds, so every keypair is memoised
 * by common name and reused across the whole suite. Do not lower the key size to
 * make the suite faster: some OpenSSL 3 builds refuse to sign with SHA-256 below
 * 2048 bits at security level 2, which would make this pass or fail depending on
 * the machine.
 *
 * openssl is used directly here rather than {@see SpCertificateGenerator},
 * so that a fixture cannot be broken by the code it is used to test.
 */
final class SpCertificateFixtures
{
    /** @var array<string, array{certificate: string, key: string}> */
    private static array $keypairs = [];

    /**
     * A certificate and the private key that signed it, both PEM.
     *
     * @return array{certificate: string, key: string}
     */
    public static function keypair(string $commonName = 'sp.example.edu.au'): array
    {
        return self::$keypairs[$commonName] ??= self::generate($commonName);
    }

    /**
     * The same, written onto the configured certificate disk.
     *
     * `$slot` is the file stem the package uses: `sp` for the certificate in
     * use, `sp_new` for the one waiting to be promoted.
     *
     * @return array{certificate: string, key: string}
     */
    public static function place(string $slot = 'sp', ?string $commonName = null): array
    {
        $keypair = self::keypair($commonName ?? $slot.'.example.edu.au');

        $disk = Storage::disk((string) config('saml.certificate.disk'));
        $path = rtrim((string) config('saml.certificate.path'), '/');

        $disk->put($path.'/'.$slot.'.crt', $keypair['certificate']);
        $disk->put($path.'/'.$slot.'.key', $keypair['key']);

        return $keypair;
    }

    /**
     * A certificate and a key that do not belong to each other, written into
     * `$slot`. Promotion has to refuse this: it looks entirely well-formed, and
     * the only symptom of accepting it is signatures nothing can verify.
     */
    public static function placeMismatched(string $slot = 'sp_new'): void
    {
        $disk = Storage::disk((string) config('saml.certificate.disk'));
        $path = rtrim((string) config('saml.certificate.path'), '/');

        $disk->put($path.'/'.$slot.'.crt', self::keypair('mismatch-cert.example.edu.au')['certificate']);
        $disk->put($path.'/'.$slot.'.key', self::keypair('mismatch-key.example.edu.au')['key']);
    }

    /**
     * @return array{certificate: string, key: string}
     */
    private static function generate(string $commonName): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(['commonName' => $commonName], $key, ['digest_alg' => 'sha256']);
        $signed = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));

        openssl_x509_export($signed, $certificate);
        openssl_pkey_export($key, $privateKey);

        return ['certificate' => $certificate, 'key' => $privateKey];
    }
}
