<?php

use Illuminate\Support\Carbon;
use NBCSIT\Sso\Support\Certificate;
use NBCSIT\Sso\Tests\Fixtures\SamlMetadataFixtures;

afterEach(function () {
    Carbon::setTestNow();
});

it('reads the thumbprint and expiry an identity provider console prints', function () {
    $certificate = Certificate::fromBase64(SamlMetadataFixtures::CERT_A);

    expect($certificate->thumbprint)->toBe(SamlMetadataFixtures::THUMBPRINT_A)
        ->and($certificate->expiresAt?->toDateString())->toBe('2036-08-09')
        ->and($certificate->subject)->toContain('idp.example.edu.au')
        ->and($certificate->describe())
        ->toBe('thumbprint '.SamlMetadataFixtures::THUMBPRINT_A.', expires 9 Aug 2036');
});

it('accepts a certificate pasted with its armour and line breaks', function () {
    $pasted = "-----BEGIN CERTIFICATE-----\n"
        .chunk_split(SamlMetadataFixtures::CERT_A, 64, "\n")
        ."-----END CERTIFICATE-----\n";

    expect(Certificate::fromBase64($pasted)->body)->toBe(SamlMetadataFixtures::CERT_A);
});

it('describes a certificate it cannot decode rather than throwing', function () {
    $certificate = Certificate::fromBase64('this is not a certificate at all');

    expect($certificate->thumbprint)->toBeNull()
        ->and($certificate->expiresAt)->toBeNull()
        ->and($certificate->subject)->toBeNull()
        ->and($certificate->hasExpired())->toBeFalse()
        ->and($certificate->describe())->toContain('could not be read');
});

it('knows when a certificate has expired', function () {
    expect(Certificate::fromBase64(SamlMetadataFixtures::CERT_A)->hasExpired())->toBeFalse();

    Carbon::setTestNow('2040-01-01');

    expect(Certificate::fromBase64(SamlMetadataFixtures::CERT_A)->hasExpired())->toBeTrue();
});

describe('the validity window', function () {
    it('reads the near end as well as the far one', function () {
        $certificate = Certificate::fromBase64(SamlMetadataFixtures::CERT_A);

        expect($certificate->startsAt)->not->toBeNull()
            ->and($certificate->startsAt->isBefore($certificate->expiresAt))->toBeTrue();
    });

    it('has no window at all when it could not be read', function () {
        expect(Certificate::fromBase64('not a certificate')->startsAt)->toBeNull();
    });

    it('is expiring soon when the far end is inside the window asked about', function () {
        $certificate = Certificate::fromBase64(SamlMetadataFixtures::CERT_A);

        expect($certificate->expiresWithin(30))->toBeFalse()
            ->and($certificate->expiresWithin(365 * 100))->toBeTrue();
    });

    it('is not expiring soon once it has expired, because that is a different and worse thing', function () {
        $this->travelTo(Certificate::fromBase64(SamlMetadataFixtures::CERT_A)->expiresAt->addDay());

        expect(Certificate::fromBase64(SamlMetadataFixtures::CERT_A)->hasExpired())->toBeTrue()
            ->and(Certificate::fromBase64(SamlMetadataFixtures::CERT_A)->expiresWithin(30))->toBeFalse();
    });

    it('is never expiring soon when it could not be read', function () {
        expect(Certificate::fromBase64('not a certificate')->expiresWithin(30))->toBeFalse();
    });
});
