<?php

use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Tests\Fixtures\SamlMetadataFixtures;

describe('the signing certificates in use', function () {
    it('prefers the rollover list', function () {
        $provider = IdentityProvider::factory()->create([
            'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
            'idp_x509_cert_multi' => [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ]);

        expect($provider->signingCertificateBodies())
            ->toBe([SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B])
            ->and($provider->signingCertificates())->toHaveCount(2)
            ->and($provider->signingCertificates()[0]->thumbprint)->toBe(SamlMetadataFixtures::THUMBPRINT_A);
    });

    it('falls back to the single column for a provider set up before the list existed', function () {
        $provider = IdentityProvider::factory()->create([
            'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
            'idp_x509_cert_multi' => null,
        ]);

        expect($provider->signingCertificateBodies())->toBe([SamlMetadataFixtures::CERT_A]);
    });

    it('has none when there is nothing in either column', function () {
        $provider = IdentityProvider::factory()->create([
            'idp_x509_cert' => '',
            'idp_x509_cert_multi' => [],
        ]);

        expect($provider->signingCertificateBodies())->toBe([])
            ->and($provider->signingCertificates())->toBe([]);
    });
});

describe('the scheduled-refresh scope', function () {
    it('takes only providers with refresh on and a URL to fetch', function () {
        $wanted = IdentityProvider::factory()->withMetadataUrl()->create(['key' => 'Scheduled']);
        IdentityProvider::factory()->create(['key' => 'No URL at all']);
        IdentityProvider::factory()->withMetadataUrl()->create(['key' => 'Switched off', 'metadata_auto_refresh' => false]);
        IdentityProvider::factory()->create(['key' => 'Blank URL', 'metadata_url' => '', 'metadata_auto_refresh' => true]);

        expect(IdentityProvider::query()->autoRefreshable()->pluck('key')->all())->toBe(['Scheduled'])
            ->and($wanted->metadata_url)->toBe('https://idp.example.edu.au/federationmetadata.xml');
    });
});
