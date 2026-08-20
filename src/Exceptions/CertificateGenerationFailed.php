<?php

namespace NBCSIT\Sso\Exceptions;

use NBCSIT\Sso\Certificates\SpCertificateGenerator;
use NBCSIT\Sso\Certificates\SpCertificateStore;
use RuntimeException;

/**
 * openssl refused to mint a certificate.
 *
 * Every one of these is a bad input rather than a broken machine — a key size
 * below what RSA allows, a distinguished name openssl will not encode, a
 * validity period it will not put in a certificate. openssl reports all three
 * the same way, by returning `false` and leaving the reason in an error queue
 * nobody reads, so the named constructors here are the only place the caller
 * finds out which of the three it was.
 *
 * Thrown by {@see SpCertificateGenerator} and caught by
 * {@see SpCertificateStore}, which turns it into a
 * report rather than letting it reach a controller.
 */
class CertificateGenerationFailed extends RuntimeException
{
    public static function theKeyCouldNotBeGenerated(int $bits): self
    {
        return new self(
            "A {$bits}-bit RSA key could not be generated. Key sizes below 2048 bits are refused by most builds of "
            .'OpenSSL, and identity providers refuse them too; leave `saml.certificate.bits` at its default unless '
            .'you have a reason to raise it.',
        );
    }

    public static function thereIsNoCommonName(): self
    {
        return new self(
            'A certificate needs a common name, and none could be worked out. Set `APP_URL` to this application\'s '
            .'address — single sign-on needs it right in any case, because the assertion consumer URL is built from '
            .'it — or name one directly in `saml.certificate.subject.commonName`.',
        );
    }

    public static function theRequestCouldNotBeBuilt(string $commonName): self
    {
        return new self(
            "OpenSSL would not build a certificate request for \"{$commonName}\". The usual causes are an empty or "
            .'over-long common name — 64 characters is the limit — or a `saml.certificate.subject.countryName` that '
            .'is not exactly two letters.',
        );
    }

    public static function theCertificateCouldNotBeSigned(int $days): self
    {
        return new self(
            "OpenSSL would not sign a certificate valid for {$days} days. The validity period has to be a positive "
            .'number of days that still lands inside the range a certificate can express.',
        );
    }
}
