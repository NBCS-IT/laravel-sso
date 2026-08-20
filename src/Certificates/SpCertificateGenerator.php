<?php

namespace NBCSIT\Sso\Certificates;

use NBCSIT\Sso\Exceptions\CertificateGenerationFailed;
use NBCSIT\Sso\Metadata\IdpMetadataSynchroniser;
use NBCSIT\Sso\Metadata\MetadataFetcher;
use NBCSIT\Sso\Support\CertificateSubject;
use NBCSIT\Sso\Support\SpKeypair;
use OpenSSLCertificateSigningRequest;

/**
 * Mints one self-signed certificate and the key that signed it.
 *
 * openssl and nothing else: no disk, no config, no container. That is what
 * makes it testable without either, and it is the same split
 * {@see MetadataFetcher} and
 * {@see IdpMetadataSynchroniser} already use — the piece
 * that can fail throws, and the piece that talks to the outside world catches
 * and reports.
 *
 * Every generation mints a **fresh** private key. The implementation this was
 * extracted from reused one key for both certificates and only created it when
 * the file was missing, which meant two things: rotating the certificate never
 * actually retired the key, and every generation after the first signed with a
 * variable that had never been assigned.
 *
 * Validity is not bounded here. Whether ten days is a sensible answer is a
 * question about policy, and policy belongs with the caller that read the
 * number off a form or a config file — {@see SpCertificateStore}, which refuses
 * anything outside the range it is willing to mint.
 */
class SpCertificateGenerator
{
    /**
     * Entra ID's floor, and everybody else's. Below 2048 an identity provider
     * declines the certificate, and most builds of OpenSSL decline to sign with
     * it at all.
     */
    public const DEFAULT_KEY_BITS = 2048;

    /**
     * Ten years. The implementation this replaces used twenty, which outlives
     * the application it protects; ten is long enough that nobody is woken by
     * it and short enough that it is not effectively permanent.
     */
    public const DEFAULT_DAYS = 3650;

    /**
     * The same digest the toolkit signs XML with by default, so one algorithm
     * appears everywhere an administrator might look rather than two.
     */
    private const DIGEST = 'sha256';

    public function generate(CertificateSubject $subject, int $days, int $bits): SpKeypair
    {
        if ($subject->commonName() === '') {
            throw CertificateGenerationFailed::thereIsNoCommonName();
        }

        $key = @openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw CertificateGenerationFailed::theKeyCouldNotBeGenerated($bits);
        }

        $request = @openssl_csr_new($subject->toArray(), $key, ['digest_alg' => self::DIGEST]);

        // Not `=== false`: the signature allows a plain `true` as well, which is
        // not something `openssl_csr_sign()` will take.
        if (! $request instanceof OpenSSLCertificateSigningRequest) {
            throw CertificateGenerationFailed::theRequestCouldNotBeBuilt($subject->commonName());
        }

        // A random serial rather than a fixed one. Two self-signed certificates
        // sharing a subject and a serial are indistinguishable to an identity
        // provider that keys its certificate store on issuer and serial, which
        // is exactly the situation a rollover creates.
        $signed = @openssl_csr_sign($request, null, $key, $days, ['digest_alg' => self::DIGEST], random_int(1, PHP_INT_MAX));

        if ($signed === false) {
            throw CertificateGenerationFailed::theCertificateCouldNotBeSigned($days);
        }

        openssl_x509_export($signed, $certificate);
        openssl_pkey_export($key, $privateKey);

        return SpKeypair::of($certificate, $privateKey);
    }
}
