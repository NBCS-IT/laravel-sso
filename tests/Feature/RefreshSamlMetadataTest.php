<?php

use Illuminate\Support\Facades\Http;
use NBCSIT\Sso\Enums\SamlMetadataOutcome;
use NBCSIT\Sso\Metadata\IdpMetadataReader;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlMetadataEvent;
use NBCSIT\Sso\Tests\Fixtures\SamlMetadataFixtures;

/**
 * @param  array<string, mixed>  $overrides
 */
function scheduledProvider(array $overrides = []): IdentityProvider
{
    return IdentityProvider::factory()->create(array_merge([
        'key' => 'Entra ID',
        'idp_entity_id' => SamlMetadataFixtures::ENTITY_ID,
        'idp_login_url' => SamlMetadataFixtures::SSO_URL,
        'idp_logout_url' => SamlMetadataFixtures::SLO_URL,
        'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        'idp_x509_cert_multi' => [SamlMetadataFixtures::CERT_A],
        'name_id_format' => 'persistent',
        'metadata_fingerprint' => app(IdpMetadataReader::class)
            ->read(SamlMetadataFixtures::document())->fingerprint(),
        'metadata_url' => 'https://idp.example.edu.au/metadata.xml',
        'metadata_auto_refresh' => true,
    ], $overrides));
}

it('says so when nothing is set up to refresh', function () {
    $this->artisan('saml:refresh-metadata')
        ->expectsOutputToContain('No identity provider is set up to refresh')
        ->assertSuccessful();
});

it('applies a rolled certificate without anybody asking', function () {
    Http::fake(['idp.example.edu.au/*' => Http::response(SamlMetadataFixtures::document(
        certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
    ))]);

    $provider = scheduledProvider();

    $this->artisan('saml:refresh-metadata')
        ->expectsOutputToContain('Signing certificate added')
        ->assertSuccessful();

    expect($provider->fresh()->idp_x509_cert_multi)
        ->toBe([SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B]);

    // Nobody ran it, so nobody owns the change in the log.
    expect(SamlMetadataEvent::query()->sole()->user_id)->toBeNull();
});

it('leaves alone a provider whose automatic refresh is off', function () {
    Http::fake();

    scheduledProvider(['metadata_auto_refresh' => false]);

    $this->artisan('saml:refresh-metadata')->assertSuccessful();

    Http::assertNothingSent();
});

it('refreshes one anyway when told to force it', function () {
    Http::fake(['idp.example.edu.au/*' => Http::response(SamlMetadataFixtures::document())]);

    scheduledProvider(['metadata_auto_refresh' => false]);

    $this->artisan('saml:refresh-metadata', ['--force' => true])
        ->expectsOutputToContain('up to date')
        ->assertSuccessful();
});

it('refreshes a single provider by name or by UUID', function () {
    Http::fake(['idp.example.edu.au/*' => Http::response(SamlMetadataFixtures::document())]);

    $provider = scheduledProvider();
    scheduledProvider(['key' => 'Other', 'idp_entity_id' => 'https://other.example.edu.au']);

    $this->artisan('saml:refresh-metadata', ['--provider' => 'Entra ID'])->assertSuccessful();
    $this->artisan('saml:refresh-metadata', ['--provider' => $provider->uuid])->assertSuccessful();

    Http::assertSentCount(2);
});

it('reports a failure in its exit code, so cron notices', function () {
    Http::fake(['idp.example.edu.au/*' => Http::response('', 500)]);

    scheduledProvider();

    $this->artisan('saml:refresh-metadata')
        ->expectsOutputToContain('answered 500')
        ->assertFailed();

    expect(SamlMetadataEvent::query()->sole()->outcome)->toBe(SamlMetadataOutcome::Failed);
});

it('names the change it held rather than applying it', function () {
    Http::fake(['idp.example.edu.au/*' => Http::response(
        SamlMetadataFixtures::document(ssoUrl: 'https://elsewhere.example.net/sso'),
    )]);

    $provider = scheduledProvider();

    $this->artisan('saml:refresh-metadata')
        ->expectsOutputToContain('Sign-in URL')
        ->assertSuccessful();

    expect($provider->fresh()->idp_login_url)->toBe(SamlMetadataFixtures::SSO_URL);
});

it('passes a document\'s warnings through', function () {
    Http::fake(['idp.example.edu.au/*' => Http::response(SamlMetadataFixtures::document(
        sloUrl: null,
        certificates: [SamlMetadataFixtures::CERT_B],
    ))]);

    scheduledProvider();

    $this->artisan('saml:refresh-metadata')
        ->expectsOutputToContain('SingleLogoutService')
        ->assertSuccessful();
});
