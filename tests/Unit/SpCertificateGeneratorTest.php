<?php

use NBCSIT\Sso\Certificates\SpCertificateGenerator;
use NBCSIT\Sso\Exceptions\CertificateGenerationFailed;
use NBCSIT\Sso\Support\CertificateSubject;

beforeEach(function () {
    $this->generator = new SpCertificateGenerator;
});

function subjectFor(array $configured = [], ?string $commonName = 'sp.example.edu.au'): CertificateSubject
{
    return CertificateSubject::fromConfig($configured, $commonName);
}

it('mints a certificate the key it returns actually signed', function () {
    $keypair = $this->generator->generate(subjectFor(), 3650, 2048);

    $certificate = openssl_x509_read($keypair->certificate);
    $key = openssl_pkey_get_private($keypair->privateKey);

    expect($certificate)->not->toBeFalse()
        ->and($key)->not->toBeFalse()
        ->and(openssl_x509_check_private_key($certificate, $key))->toBeTrue();
});

it('writes the private key as PKCS#8 PEM, which is the only form the toolkit re-armours correctly', function () {
    $keypair = $this->generator->generate(subjectFor(), 3650, 2048);

    expect($keypair->privateKey)->toStartWith('-----BEGIN PRIVATE KEY-----')
        ->and($keypair->certificate)->toStartWith('-----BEGIN CERTIFICATE-----');
});

it('carries the subject it was given', function () {
    $keypair = $this->generator->generate(
        subjectFor(['countryName' => 'AU', 'organizationName' => 'Example School'], 'sso.example.edu.au'),
        3650,
        2048,
    );

    $parsed = openssl_x509_parse($keypair->certificate);

    expect($parsed['subject']['CN'])->toBe('sso.example.edu.au')
        ->and($parsed['subject']['C'])->toBe('AU')
        ->and($parsed['subject']['O'])->toBe('Example School');
});

it('expires when it was asked to', function () {
    $keypair = $this->generator->generate(subjectFor(), 30, 2048);

    expect($keypair->details()->expiresAt->diffInDays(now()))->toBeLessThan(31)
        ->and($keypair->details()->expiresAt->isAfter(now()->addDays(28)))->toBeTrue();
});

it('honours the key size', function () {
    $keypair = $this->generator->generate(subjectFor(), 3650, 3072);

    $details = openssl_pkey_get_details(openssl_pkey_get_private($keypair->privateKey));

    expect($details['bits'])->toBe(3072);
});

it('mints a fresh key every time, so rotating a certificate actually retires its key', function () {
    $first = $this->generator->generate(subjectFor(), 3650, 2048);
    $second = $this->generator->generate(subjectFor(), 3650, 2048);

    expect($second->privateKey)->not->toBe($first->privateKey)
        ->and($second->details()->thumbprint)->not->toBe($first->details()->thumbprint);
});

it('gives each certificate its own serial, so a provider keying on issuer and serial can tell them apart', function () {
    $first = openssl_x509_parse($this->generator->generate(subjectFor(), 3650, 2048)->certificate);
    $second = openssl_x509_parse($this->generator->generate(subjectFor(), 3650, 2048)->certificate);

    expect($first['serialNumber'])->not->toBe($second['serialNumber'])
        ->and($first['serialNumber'])->not->toBe('0');
});

it('refuses a key size openssl will not generate', function () {
    $this->generator->generate(subjectFor(), 3650, 64);
})->throws(CertificateGenerationFailed::class, 'A 64-bit RSA key could not be generated');

it('refuses a subject openssl will not encode', function () {
    $this->generator->generate(subjectFor(['countryName' => 'AUS']), 3650, 2048);
})->throws(CertificateGenerationFailed::class, 'OpenSSL would not build a certificate request');

it('refuses a validity period openssl will not sign', function () {
    $this->generator->generate(subjectFor(), -1, 2048);
})->throws(CertificateGenerationFailed::class, 'OpenSSL would not sign a certificate valid for -1 days');

it('refuses when nothing can be worked out to call it', function () {
    config(['app.url' => '', 'app.name' => '']);

    $this->generator->generate(CertificateSubject::fromConfig([]), 3650, 2048);
})->throws(CertificateGenerationFailed::class, 'A certificate needs a common name');
