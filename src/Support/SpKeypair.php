<?php

namespace NBCSIT\Sso\Support;

use NBCSIT\Sso\Certificates\SpCertificateStore;

/**
 * A freshly minted certificate and the private key that signed it, in memory.
 *
 * Both are full PEM, armour and all, and that is deliberate rather than
 * incidental. The toolkit's `Utils::formatPrivateKey()` decides between PKCS#8
 * and PKCS#1 by looking for the armour: given a headerless body it assumes
 * PKCS#1 and wraps it as an RSA private key, whatever the bytes actually are.
 * `openssl_pkey_export()` emits PKCS#8. Strip the armour on the way to disk, as
 * the implementation this was extracted from did, and what comes back is a
 * PKCS#8 body inside PKCS#1 armour that nothing can read — a failure that shows
 * up days later, the first time somebody switches signing on.
 *
 * The two live together because they are only ever useful together: a
 * certificate whose key has been lost is not a certificate this application can
 * sign with, and {@see SpCertificateStore} writes them
 * as a pair for that reason.
 */
final readonly class SpKeypair
{
    private function __construct(
        /** PEM, `-----BEGIN CERTIFICATE-----` and all. */
        public string $certificate,
        /** PEM, PKCS#8, as `openssl_pkey_export()` produces it. */
        public string $privateKey,
    ) {}

    public static function of(string $certificate, string $privateKey): self
    {
        return new self($certificate, $privateKey);
    }

    /**
     * The certificate in the terms an administrator recognises — thumbprint and
     * expiry, rather than a page of base64.
     */
    public function details(): Certificate
    {
        return Certificate::fromBase64($this->certificate);
    }
}
