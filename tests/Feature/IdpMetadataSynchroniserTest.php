<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use NBCSIT\Sso\Enums\SamlMetadataOutcome;
use NBCSIT\Sso\Enums\SamlMetadataSource;
use NBCSIT\Sso\Metadata\IdpMetadataReader;
use NBCSIT\Sso\Metadata\IdpMetadataSynchroniser;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlMetadataEvent;
use NBCSIT\Sso\Tests\Fixtures\SamlMetadataFixtures;
use NBCSIT\Sso\Tests\Fixtures\User;

beforeEach(function () {
    $this->synchroniser = app(IdpMetadataSynchroniser::class);
});

/**
 * A provider configured exactly as {@see SamlMetadataFixtures::document()}
 * describes one, so a test only has to say what moved.
 *
 * @param  array<string, mixed>  $overrides
 */
function configuredProvider(array $overrides = []): IdentityProvider
{
    $document = SamlMetadataFixtures::document();

    return IdentityProvider::factory()->create(array_merge([
        'key' => 'Entra ID',
        'idp_entity_id' => SamlMetadataFixtures::ENTITY_ID,
        'idp_login_url' => SamlMetadataFixtures::SSO_URL,
        'idp_logout_url' => SamlMetadataFixtures::SLO_URL,
        'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        'idp_x509_cert_multi' => [SamlMetadataFixtures::CERT_A],
        'name_id_format' => 'persistent',
        'metadata_fingerprint' => app(IdpMetadataReader::class)->read($document)->fingerprint(),
    ], $overrides));
}

describe('adding a provider from a document', function () {
    it('takes every value out of the metadata', function () {
        $report = $this->synchroniser->createFromXml('Entra ID', SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ));

        expect($report->succeeded())->toBeTrue()
            ->and($report->outcome)->toBe(SamlMetadataOutcome::Created);

        $provider = $report->provider;

        expect($provider->key)->toBe('Entra ID')
            ->and($provider->idp_entity_id)->toBe(SamlMetadataFixtures::ENTITY_ID)
            ->and($provider->idp_login_url)->toBe(SamlMetadataFixtures::SSO_URL)
            ->and($provider->idp_logout_url)->toBe(SamlMetadataFixtures::SLO_URL)
            ->and($provider->name_id_format)->toBe('persistent')
            ->and($provider->idp_x509_cert)->toBe(SamlMetadataFixtures::CERT_A)
            ->and($provider->idp_x509_cert_multi)
            ->toBe([SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B])
            ->and($provider->metadata_fingerprint)->not->toBeNull()
            ->and($provider->uuid)->not->toBeEmpty();
    });

    it('records the addition against the provider', function () {
        $user = User::factory()->create();

        $report = $this->synchroniser->createFromXml('Entra ID', SamlMetadataFixtures::document(), userId: $user->id);

        $event = SamlMetadataEvent::query()->sole();

        expect($event->tenant_id)->toBe($report->provider->getKey())
            ->and($event->outcome)->toBe(SamlMetadataOutcome::Created)
            ->and($event->source)->toBe(SamlMetadataSource::Upload)
            ->and($event->user_id)->toBe($user->id)
            ->and($event->actor())->toBe($user->name);
    });

    it('stores the refresh URL and switches automatic refresh on', function () {
        $report = $this->synchroniser->createFromXml(
            'Entra ID',
            SamlMetadataFixtures::document(),
            metadataUrl: 'https://idp.example.edu.au/metadata.xml',
            autoRefresh: true,
        );

        expect($report->provider->metadata_url)->toBe('https://idp.example.edu.au/metadata.xml')
            ->and($report->provider->metadata_auto_refresh)->toBeTrue();
    });

    it('will not switch automatic refresh on with no URL to refresh from', function () {
        $report = $this->synchroniser->createFromXml('Entra ID', SamlMetadataFixtures::document(), autoRefresh: true);

        expect($report->provider->metadata_auto_refresh)->toBeFalse();
    });

    it('warns when the metadata URL is not HTTPS', function () {
        $report = $this->synchroniser->createFromXml(
            'Entra ID',
            SamlMetadataFixtures::document(),
            metadataUrl: 'http://idp.example.edu.au/metadata.xml',
        );

        expect($report->warnings)->toContain(
            'The metadata URL is not HTTPS, so what it returns cannot be trusted to have come from the identity provider.',
        );
    });

    it('passes the document\'s own warnings on', function () {
        $report = $this->synchroniser->createFromXml('Entra ID', SamlMetadataFixtures::document(sloUrl: null));

        expect($report->warnings)->toHaveCount(1)
            ->and($report->warnings[0])->toContain('SingleLogoutService');
    });

    it('refuses a document it cannot read, and says so in the log', function () {
        $report = $this->synchroniser->createFromXml('Entra ID', 'not xml');

        expect($report->succeeded())->toBeFalse()
            ->and($report->provider)->toBeNull()
            ->and(IdentityProvider::query()->count())->toBe(0);

        $event = SamlMetadataEvent::query()->sole();

        expect($event->outcome)->toBe(SamlMetadataOutcome::Failed)
            ->and($event->tenant_id)->toBeNull()
            ->and($event->provider_name)->toBe('Entra ID')
            ->and($event->actor())->toBe('Scheduled check');
    });

    it('refuses to add a second provider with the same entity ID', function () {
        configuredProvider();

        $report = $this->synchroniser->createFromXml('Entra ID again', SamlMetadataFixtures::document());

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('already set up here')
            ->and(IdentityProvider::query()->count())->toBe(1);
    });

    it('picks one provider out of a federation file', function () {
        $xml = SamlMetadataFixtures::federation(['https://one.example.edu.au', 'https://two.example.edu.au']);

        $report = $this->synchroniser->createFromXml('Second', $xml, entityId: 'https://two.example.edu.au');

        expect($report->provider->idp_entity_id)->toBe('https://two.example.edu.au');
    });
});

describe('adding a provider from a URL', function () {
    it('fetches the document and remembers where it came from', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response(SamlMetadataFixtures::document())]);

        $report = $this->synchroniser->createFromUrl('Entra ID', 'https://idp.example.edu.au/metadata.xml', autoRefresh: true);

        expect($report->succeeded())->toBeTrue()
            ->and($report->provider->metadata_url)->toBe('https://idp.example.edu.au/metadata.xml')
            ->and($report->provider->metadata_auto_refresh)->toBeTrue();

        expect(SamlMetadataEvent::query()->sole()->source)->toBe(SamlMetadataSource::Url);
    });

    it('reports a URL that answers with an error', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response('nope', 404)]);

        $report = $this->synchroniser->createFromUrl('Entra ID', 'https://idp.example.edu.au/metadata.xml');

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('answered 404')
            ->and(IdentityProvider::query()->count())->toBe(0)
            ->and(SamlMetadataEvent::query()->sole()->outcome)->toBe(SamlMetadataOutcome::Failed);
    });

    it('reports a URL it cannot reach', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: could not resolve host'));

        $report = $this->synchroniser->createFromUrl('Entra ID', 'https://idp.example.edu.au/metadata.xml');

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('Could not reach');
    });

    it('refuses a response too large to be metadata', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response(str_repeat('x', 3 * 1024 * 1024))]);

        $report = $this->synchroniser->createFromUrl('Entra ID', 'https://idp.example.edu.au/metadata.xml');

        expect($report->message)->toContain('2 MB');
    });
});

describe('refreshing a provider', function () {
    it('does nothing, and writes no history, when the metadata has not moved', function () {
        $provider = configuredProvider(['metadata_checked_at' => now()->subWeek()]);

        $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document());

        expect($report->outcome)->toBe(SamlMetadataOutcome::Unchanged)
            ->and($report->succeeded())->toBeTrue()
            ->and(SamlMetadataEvent::query()->count())->toBe(0)
            ->and($provider->fresh()->metadata_checked_at->isToday())->toBeTrue();
    });

    it('applies a new signing certificate on its own', function () {
        $provider = configuredProvider();

        $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ));

        expect($report->outcome)->toBe(SamlMetadataOutcome::Updated)
            ->and($report->applied)->toHaveCount(1)
            ->and($report->applied[0]->label)->toBe('Signing certificate added')
            ->and($report->applied[0]->to)->toContain('thumbprint')
            ->and($report->pending)->toBe([]);

        $provider->refresh();

        expect($provider->idp_x509_cert_multi)
            ->toBe([SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B])
            ->and($provider->metadata_synced_at)->not->toBeNull()
            ->and($provider->pending_metadata)->toBeNull();
    });

    it('reports a certificate the provider has withdrawn', function () {
        $provider = configuredProvider([
            'idp_x509_cert_multi' => [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
            'metadata_fingerprint' => app(IdpMetadataReader::class)->read(SamlMetadataFixtures::document(
                certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
            ))->fingerprint(),
        ]);

        $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document());

        expect($report->applied)->toHaveCount(1)
            ->and($report->applied[0]->label)->toBe('Signing certificate withdrawn')
            ->and($provider->fresh()->idp_x509_cert_multi)->toBe([SamlMetadataFixtures::CERT_A]);
    });

    it('holds a change of sign-in URL rather than writing it', function () {
        $provider = configuredProvider();

        $report = $this->synchroniser->refreshFromXml(
            $provider,
            SamlMetadataFixtures::document(ssoUrl: 'https://elsewhere.example.net/sso'),
        );

        expect($report->outcome)->toBe(SamlMetadataOutcome::Held)
            ->and($report->applied)->toBe([])
            ->and($report->pending)->toHaveCount(1)
            ->and($report->pending[0]->field)->toBe('idp_login_url')
            ->and($report->pending[0]->guarded)->toBeTrue()
            ->and($report->message)->toContain('waiting for you');

        $provider->refresh();

        expect($provider->idp_login_url)->toBe(SamlMetadataFixtures::SSO_URL)
            ->and($provider->hasPendingChanges())->toBeTrue()
            ->and($provider->pending_metadata_at)->not->toBeNull()
            ->and($provider->metadata_synced_at)->toBeNull();
    });

    it('holds a change of NameID format, which decides how a person is matched', function () {
        $provider = configuredProvider();

        $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
            nameIdFormat: 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        ));

        expect($report->pending[0]->field)->toBe('name_id_format')
            ->and($report->pending[0]->to)->toBe('emailAddress')
            ->and($provider->fresh()->name_id_format)->toBe('persistent');
    });

    it('applies the certificate and holds the endpoint in the same run', function () {
        $provider = configuredProvider();

        $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
            sloUrl: 'https://sts.example.edu.au/adfs/ls/signout',
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ));

        expect($report->outcome)->toBe(SamlMetadataOutcome::Updated)
            ->and($report->applied)->toHaveCount(1)
            ->and($report->pending)->toHaveCount(1);

        $provider->refresh();

        expect($provider->idp_x509_cert_multi)
            ->toBe([SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B])
            ->and($provider->idp_logout_url)->toBe(SamlMetadataFixtures::SLO_URL);
    });

    it('records what it changed, so the morning after has something to read', function () {
        $provider = configuredProvider();

        $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ));

        $event = SamlMetadataEvent::query()->sole();

        expect($event->outcome)->toBe(SamlMetadataOutcome::Updated)
            ->and($event->tenant_id)->toBe($provider->getKey())
            ->and($event->changeList())->toHaveCount(1)
            ->and($event->changeList()[0]->describe())->toContain('Signing certificate');
    });

    /*
    | Rolling a key means the outgoing and the incoming certificate overlap for
    | a while, and keeping up with that unattended is the entire reason a
    | metadata URL exists. A document that shares no certificate at all with what
    | is configured is not rolling a key — it is naming the keys this application
    | will accept signatures from, which is the whole of authentication, from a
    | document whose own signature nothing here checks.
    */
    describe('a document that replaces every signing certificate', function () {
        it('holds the replacement rather than writing it', function () {
            $provider = configuredProvider();

            $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
                certificates: [SamlMetadataFixtures::CERT_B],
            ));

            expect($report->outcome)->toBe(SamlMetadataOutcome::Held)
                ->and($report->applied)->toBe([])
                ->and($report->pending)->toHaveCount(2)
                ->and($report->pending[0]->guarded)->toBeTrue();

            $provider->refresh();

            expect($provider->idp_x509_cert)->toBe(SamlMetadataFixtures::CERT_A)
                ->and($provider->idp_x509_cert_multi)->toBe([SamlMetadataFixtures::CERT_A])
                ->and($provider->pending_certificates)->toBe([SamlMetadataFixtures::CERT_B])
                ->and($provider->metadata_synced_at)->toBeNull();
        });

        it('is written once an administrator agrees to it', function () {
            $provider = configuredProvider();

            $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
                certificates: [SamlMetadataFixtures::CERT_B],
            ));

            $report = $this->synchroniser->applyPending($provider->fresh());

            expect($report->succeeded())->toBeTrue();

            $provider->refresh();

            expect($provider->idp_x509_cert)->toBe(SamlMetadataFixtures::CERT_B)
                ->and($provider->idp_x509_cert_multi)->toBe([SamlMetadataFixtures::CERT_B])
                ->and($provider->pending_certificates)->toBeNull()
                ->and($provider->hasPendingChanges())->toBeFalse();
        });

        it('leaves the certificates in use alone when the administrator says no', function () {
            $provider = configuredProvider();

            $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
                certificates: [SamlMetadataFixtures::CERT_B],
            ));

            $this->synchroniser->discardPending($provider->fresh());

            $provider->refresh();

            expect($provider->idp_x509_cert)->toBe(SamlMetadataFixtures::CERT_A)
                ->and($provider->pending_certificates)->toBeNull()
                ->and($provider->hasPendingChanges())->toBeFalse();
        });

        it('is not what a provider with no certificate yet is doing', function () {
            $provider = configuredProvider([
                'idp_x509_cert' => '',
                'idp_x509_cert_multi' => null,
                'metadata_fingerprint' => null,
            ]);

            $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document());

            expect($report->outcome)->toBe(SamlMetadataOutcome::Updated)
                ->and($provider->fresh()->idp_x509_cert)->toBe(SamlMetadataFixtures::CERT_A);
        });
    });

    it('takes a fingerprint for a provider that was set up by hand', function () {
        $provider = configuredProvider(['metadata_fingerprint' => null]);

        $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document());

        expect($report->outcome)->toBe(SamlMetadataOutcome::Unchanged)
            ->and($provider->fresh()->metadata_fingerprint)->not->toBeNull()
            ->and(SamlMetadataEvent::query()->count())->toBe(0);
    });

    it('sees a provider that changed its own entity ID', function () {
        $provider = configuredProvider();

        $report = $this->synchroniser->refreshFromXml(
            $provider,
            SamlMetadataFixtures::document(entityId: 'https://sts.example.edu.au/adfs/services/trust/v2'),
        );

        expect($report->pending)->toHaveCount(1)
            ->and($report->pending[0]->field)->toBe('idp_entity_id')
            ->and($report->pending[0]->to)->toBe('https://sts.example.edu.au/adfs/services/trust/v2')
            ->and($provider->fresh()->idp_entity_id)->toBe(SamlMetadataFixtures::ENTITY_ID);
    });

    it('records the failure on the provider when the document cannot be read', function () {
        $provider = configuredProvider();

        $report = $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::serviceProviderDocument());

        expect($report->succeeded())->toBeFalse();

        $provider->refresh();

        expect($provider->metadata_error)->toContain('no IDPSSODescriptor')
            ->and($provider->metadata_checked_at)->not->toBeNull()
            ->and(SamlMetadataEvent::query()->sole()->outcome)->toBe(SamlMetadataOutcome::Failed);
    });
});

describe('refreshing from the stored URL', function () {
    it('fetches, applies and clears an earlier error', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response(SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ))]);

        $provider = configuredProvider([
            'metadata_url' => 'https://idp.example.edu.au/metadata.xml',
            'metadata_auto_refresh' => true,
            'metadata_error' => 'yesterday it timed out',
        ]);

        $report = $this->synchroniser->refreshFromUrl($provider);

        expect($report->outcome)->toBe(SamlMetadataOutcome::Updated)
            ->and($provider->fresh()->metadata_error)->toBeNull();
    });

    it('refuses when there is no URL saved', function () {
        $provider = configuredProvider();

        $report = $this->synchroniser->refreshFromUrl($provider);

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('no metadata URL')
            // Nothing was attempted, so there is nothing to log.
            ->and(SamlMetadataEvent::query()->count())->toBe(0);
    });

    /*
    | A warning about plain HTTP is the right answer to a person standing there
    | setting a provider up, who can weigh it. It is the wrong answer at 03:15,
    | where the document decides whose signature is accepted and nobody is
    | reading.
    */
    it('refuses to refresh over plain HTTP rather than warning about it', function () {
        Http::fake();

        $provider = configuredProvider(['metadata_url' => 'http://idp.example.edu.au/metadata.xml']);

        $report = $this->synchroniser->refreshFromUrl($provider);

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('is not HTTPS')
            ->and($provider->fresh()->metadata_error)->toContain('is not HTTPS')
            ->and(SamlMetadataEvent::query()->sole()->outcome)->toBe(SamlMetadataOutcome::Failed);

        Http::assertNothingSent();
    });

    it('writes the fetch failure where the settings page will show it', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response('', 503)]);

        $provider = configuredProvider(['metadata_url' => 'https://idp.example.edu.au/metadata.xml']);

        $report = $this->synchroniser->refreshFromUrl($provider);

        expect($report->succeeded())->toBeFalse()
            ->and($provider->fresh()->metadata_error)->toContain('answered 503')
            ->and(SamlMetadataEvent::query()->sole()->metadata_url)
            ->toBe('https://idp.example.edu.au/metadata.xml');
    });
});

describe('held changes', function () {
    it('writes them when an administrator applies them', function () {
        $provider = configuredProvider();

        $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
            ssoUrl: 'https://elsewhere.example.net/sso',
            sloUrl: null,
        ));

        $report = $this->synchroniser->applyPending($provider->fresh());

        expect($report->succeeded())->toBeTrue();

        $provider->refresh();

        expect($provider->idp_login_url)->toBe('https://elsewhere.example.net/sso')
            // A guarded value the metadata dropped becomes the empty string the
            // package treats as unset, because the column is NOT NULL.
            ->and($provider->idp_logout_url)->toBe('')
            ->and($provider->pending_metadata)->toBeNull()
            ->and($provider->pending_metadata_at)->toBeNull()
            ->and($provider->metadata_synced_at)->not->toBeNull();

        expect(SamlMetadataEvent::query()->latest('id')->first()->outcome)->toBe(SamlMetadataOutcome::Updated);
    });

    it('keeps the current configuration when they are discarded', function () {
        $provider = configuredProvider();

        $this->synchroniser->refreshFromXml($provider, SamlMetadataFixtures::document(
            ssoUrl: 'https://elsewhere.example.net/sso',
        ));

        $fingerprint = $provider->fresh()->metadata_fingerprint;

        $report = $this->synchroniser->discardPending($provider->fresh());

        expect($report->succeeded())->toBeTrue()
            ->and($report->message)->toContain('Discarded 1 held change');

        $provider->refresh();

        expect($provider->idp_login_url)->toBe(SamlMetadataFixtures::SSO_URL)
            ->and($provider->pending_metadata)->toBeNull()
            // Left matching the document, so tomorrow's run does not raise the
            // same change again.
            ->and($provider->metadata_fingerprint)->toBe($fingerprint);

        expect(SamlMetadataEvent::query()->latest('id')->first()->outcome)->toBe(SamlMetadataOutcome::Held);
    });

    it('has nothing to apply or discard when none are held', function () {
        $provider = configuredProvider();

        expect($this->synchroniser->applyPending($provider)->message)->toContain('no held changes to apply')
            ->and($this->synchroniser->discardPending($provider)->message)->toContain('no held changes to discard')
            ->and(SamlMetadataEvent::query()->count())->toBe(0);
    });
});
