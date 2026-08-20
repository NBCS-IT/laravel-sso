<?php

use Illuminate\Support\Facades\Http;
use NBCSIT\Sso\Metadata\IdpMetadataReader;
use NBCSIT\Sso\Metadata\IdpMetadataSynchroniser;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlMetadataEvent;
use NBCSIT\Sso\Settings\SamlSettings;
use NBCSIT\Sso\Tests\Fixtures\SamlMetadataFixtures;

beforeEach(function () {
    $this->admin = userWithPermissions(config('saml.gate'));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function providerFromMetadata(array $overrides = []): IdentityProvider
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
    ], $overrides));
}

describe('authorisation', function () {
    it('turns away a user without the settings permission', function () {
        $provider = providerFromMetadata();
        $viewer = userWithPermissions('some other ability');

        $this->actingAs($viewer)->post(route('admin.settings.saml.metadata.store'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.settings.saml.metadata.show', $provider))->assertForbidden();
        $this->actingAs($viewer)->put(route('admin.settings.saml.metadata.update', $provider))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.settings.saml.metadata.refresh', $provider))->assertForbidden();
        $this->actingAs($viewer)->post(route('admin.settings.saml.metadata.pending.apply', $provider))->assertForbidden();
        $this->actingAs($viewer)->delete(route('admin.settings.saml.metadata.pending.discard', $provider))->assertForbidden();
    });
});

describe('adding a provider', function () {
    it('reads an uploaded document', function () {
        $response = $this->actingAs($this->admin)->post(route('admin.settings.saml.metadata.store'), [
            'provider_name' => 'Entra ID',
            'file' => SamlMetadataFixtures::upload(SamlMetadataFixtures::document()),
        ]);

        $provider = IdentityProvider::query()->sole();

        $response->assertRedirect(route('admin.settings.saml.metadata.show', $provider))
            ->assertSessionHas('status');

        expect($provider->idp_login_url)->toBe(SamlMetadataFixtures::SSO_URL)
            ->and($provider->idp_x509_cert)->toBe(SamlMetadataFixtures::CERT_A);
    });

    it('fetches a metadata URL', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response(SamlMetadataFixtures::document())]);

        $this->actingAs($this->admin)->post(route('admin.settings.saml.metadata.store'), [
            'provider_name' => 'Entra ID',
            'metadata_url' => 'https://idp.example.edu.au/metadata.xml',
            'auto_refresh' => '1',
        ])->assertSessionHas('status');

        $provider = IdentityProvider::query()->sole();

        expect($provider->metadata_url)->toBe('https://idp.example.edu.au/metadata.xml')
            ->and($provider->metadata_auto_refresh)->toBeTrue();
    });

    it('wants either a file or a URL', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.metadata.store'), ['provider_name' => 'Entra ID'])
            ->assertSessionHasErrors(['file', 'metadata_url']);
    });

    it('selects the first provider added, without switching sign-on on', function () {
        $this->actingAs($this->admin)->post(route('admin.settings.saml.metadata.store'), [
            'provider_name' => 'Entra ID',
            'file' => SamlMetadataFixtures::upload(SamlMetadataFixtures::document()),
        ]);

        $settings = app(SamlSettings::class)->refresh();

        expect($settings->default_uuid)->toBe(IdentityProvider::query()->sole()->uuid)
            ->and($settings->enabled)->toBeFalse();
    });

    it('leaves an already chosen provider alone', function () {
        $existing = providerFromMetadata(['idp_entity_id' => 'https://already.example.edu.au']);
        $settings = app(SamlSettings::class);
        $settings->default_uuid = $existing->uuid;
        $settings->save();

        $this->actingAs($this->admin)->post(route('admin.settings.saml.metadata.store'), [
            'provider_name' => 'Entra ID',
            'file' => SamlMetadataFixtures::upload(SamlMetadataFixtures::document()),
        ]);

        expect(app(SamlSettings::class)->refresh()->default_uuid)->toBe($existing->uuid);
    });

    it('comes back with the reason when the document cannot be read', function () {
        $this->actingAs($this->admin)->post(route('admin.settings.saml.metadata.store'), [
            'provider_name' => 'Entra ID',
            'file' => SamlMetadataFixtures::upload(SamlMetadataFixtures::serviceProviderDocument()),
        ])->assertSessionHas('error');

        expect(IdentityProvider::query()->count())->toBe(0);
    });
});

describe('the provider\'s metadata page', function () {
    it('shows the source, the certificates and the history', function () {
        $provider = providerFromMetadata(['metadata_url' => 'https://idp.example.edu.au/metadata.xml']);
        SamlMetadataEvent::factory()->for($provider, 'provider')->create(['message' => 'Applied a new certificate.']);

        $this->actingAs($this->admin)
            ->get(route('admin.settings.saml.metadata.show', $provider))
            ->assertOk()
            ->assertSee('https://idp.example.edu.au/metadata.xml')
            ->assertSee(SamlMetadataFixtures::THUMBPRINT_A)
            ->assertSee('Applied a new certificate.');
    });

    it('shows held changes with an apply and a discard', function () {
        $provider = providerFromMetadata();

        app(IdpMetadataSynchroniser::class)->refreshFromXml(
            $provider,
            SamlMetadataFixtures::document(ssoUrl: 'https://elsewhere.example.net/sso'),
        );

        $this->actingAs($this->admin)
            ->get(route('admin.settings.saml.metadata.show', $provider))
            ->assertOk()
            ->assertSee('Changes waiting for you')
            ->assertSee('https://elsewhere.example.net/sso');
    });
});

describe('saving the metadata source', function () {
    it('stores the URL and the refresh switch', function () {
        $provider = providerFromMetadata();

        $this->actingAs($this->admin)->put(route('admin.settings.saml.metadata.update', $provider), [
            'metadata_url' => 'https://idp.example.edu.au/metadata.xml',
            'auto_refresh' => '1',
        ])->assertSessionHas('status');

        $provider->refresh();

        expect($provider->metadata_url)->toBe('https://idp.example.edu.au/metadata.xml')
            ->and($provider->metadata_auto_refresh)->toBeTrue();
    });

    it('will not leave automatic refresh on with the URL removed', function () {
        $provider = providerFromMetadata([
            'metadata_url' => 'https://idp.example.edu.au/metadata.xml',
            'metadata_auto_refresh' => true,
        ]);

        $this->actingAs($this->admin)->put(route('admin.settings.saml.metadata.update', $provider), [
            'metadata_url' => '',
            'auto_refresh' => '1',
        ]);

        $provider->refresh();

        expect($provider->metadata_url)->toBeNull()
            ->and($provider->metadata_auto_refresh)->toBeFalse();
    });
});

describe('refreshing', function () {
    it('reads the stored URL', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response(SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ))]);

        $provider = providerFromMetadata(['metadata_url' => 'https://idp.example.edu.au/metadata.xml']);

        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.metadata.refresh', $provider))
            ->assertSessionHas('status');

        expect($provider->fresh()->idp_x509_cert_multi)->toHaveCount(2);
    });

    it('prefers an uploaded document over the stored URL', function () {
        Http::fake();

        $provider = providerFromMetadata(['metadata_url' => 'https://idp.example.edu.au/metadata.xml']);

        $this->actingAs($this->admin)->post(route('admin.settings.saml.metadata.refresh', $provider), [
            'file' => SamlMetadataFixtures::upload(SamlMetadataFixtures::document(
                certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
            )),
        ])->assertSessionHas('status');

        expect($provider->fresh()->idp_x509_cert_multi)
            ->toBe([SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B]);

        Http::assertNothingSent();
    });

    it('reports a failure as an error rather than a status', function () {
        $provider = providerFromMetadata();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.metadata.refresh', $provider))
            ->assertSessionHas('error');
    });
});

describe('held changes', function () {
    beforeEach(function () {
        $this->provider = providerFromMetadata();

        app(IdpMetadataSynchroniser::class)->refreshFromXml(
            $this->provider,
            SamlMetadataFixtures::document(ssoUrl: 'https://elsewhere.example.net/sso'),
        );

        $this->provider->refresh();
    });

    it('applies them', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.metadata.pending.apply', $this->provider))
            ->assertSessionHas('status');

        expect($this->provider->fresh()->idp_login_url)->toBe('https://elsewhere.example.net/sso');
    });

    it('discards them', function () {
        $this->actingAs($this->admin)
            ->delete(route('admin.settings.saml.metadata.pending.discard', $this->provider))
            ->assertSessionHas('status');

        $this->provider->refresh();

        expect($this->provider->idp_login_url)->toBe(SamlMetadataFixtures::SSO_URL)
            ->and($this->provider->hasPendingChanges())->toBeFalse();
    });

    it('says so when there is nothing held', function () {
        $other = providerFromMetadata(['idp_entity_id' => 'https://other.example.edu.au']);

        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.metadata.pending.apply', $other))
            ->assertSessionHas('error');

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.saml.metadata.pending.discard', $other))
            ->assertSessionHas('error');
    });
});
