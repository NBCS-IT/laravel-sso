<?php

use NBCSIT\Saml2\OneLoginBuilder;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\MultiCertificateOneLoginBuilder;
use NBCSIT\Sso\SsoServiceProvider;
use NBCSIT\Sso\Support\Certificate;
use NBCSIT\Sso\Tests\Fixtures\SamlMetadataFixtures;
use NBCSIT\Sso\Tests\Fixtures\SpCertificateFixtures;
use OneLogin\Saml2\Utils as OneLoginUtils;

/**
 * @return array<string, mixed>
 */
function idpSettingsFor(IdentityProvider $provider): array
{
    app(OneLoginBuilder::class)->withTenant($provider)->bootstrap();

    return app('OneLogin_Saml2_Auth')->getSettings()->getIdPData();
}

/**
 * @return array<string, mixed>
 */
function spSettingsFor(IdentityProvider $provider): array
{
    app(OneLoginBuilder::class)->withTenant($provider)->bootstrap();

    return app('OneLogin_Saml2_Auth')->getSettings()->getSPData();
}

/**
 * @return array<string, mixed>
 */
function securitySettingsFor(IdentityProvider $provider): array
{
    app(OneLoginBuilder::class)->withTenant($provider)->bootstrap();

    return app('OneLogin_Saml2_Auth')->getSettings()->getSecurityData();
}

it('is what the package resolves, so the middleware gets it', function () {
    expect(app(OneLoginBuilder::class))->toBeInstanceOf(MultiCertificateOneLoginBuilder::class);
});

it('resolves tenants as the model that knows about the certificate list', function () {
    expect(config('saml2.tenantModel'))->toBe(IdentityProvider::class);
});

it('offers every certificate to the toolkit during a rollover', function () {
    $provider = IdentityProvider::factory()->create([
        'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        'idp_x509_cert_multi' => [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        'name_id_format' => 'persistent',
    ]);

    $idp = idpSettingsFor($provider);

    expect($idp['x509certMulti']['signing'])->toHaveCount(2)
        ->and(Certificate::normalise($idp['x509certMulti']['signing'][0]))->toBe(SamlMetadataFixtures::CERT_A)
        ->and(Certificate::normalise($idp['x509certMulti']['signing'][1]))->toBe(SamlMetadataFixtures::CERT_B)
        // Kept alongside for any path that reads only the single certificate.
        ->and(Certificate::normalise($idp['x509cert']))->toBe(SamlMetadataFixtures::CERT_A);
});

it('sends one certificate the way the package always has', function () {
    $provider = IdentityProvider::factory()->create([
        'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        'idp_x509_cert_multi' => [SamlMetadataFixtures::CERT_A],
        'name_id_format' => 'persistent',
    ]);

    $idp = idpSettingsFor($provider);

    expect($idp)->not->toHaveKey('x509certMulti')
        ->and(Certificate::normalise($idp['x509cert']))->toBe(SamlMetadataFixtures::CERT_A);
});

it('copes with a provider configured before the certificate list existed', function () {
    $provider = IdentityProvider::factory()->create([
        'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        'idp_x509_cert_multi' => null,
        'name_id_format' => 'persistent',
    ]);

    $idp = idpSettingsFor($provider);

    expect($idp)->not->toHaveKey('x509certMulti')
        ->and(Certificate::normalise($idp['x509cert']))->toBe(SamlMetadataFixtures::CERT_A);
});

it('keeps the package\'s proxy handling', function () {
    config(['saml2.proxyVars' => true]);

    idpSettingsFor(IdentityProvider::factory()->create([
        'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        'name_id_format' => 'persistent',
    ]));

    expect(OneLoginUtils::getProxyVars())->toBeTrue();

    // Static on the toolkit, so it outlives the test unless it is put back.
    OneLoginUtils::setProxyVars(false);
});

it('still puts the entity ID, the endpoints and the NameID format in place', function () {
    $provider = IdentityProvider::factory()->create([
        'idp_entity_id' => SamlMetadataFixtures::ENTITY_ID,
        'idp_login_url' => SamlMetadataFixtures::SSO_URL,
        'idp_logout_url' => SamlMetadataFixtures::SLO_URL,
        'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
        'name_id_format' => 'emailAddress',
    ]);

    $idp = idpSettingsFor($provider);

    expect($idp['entityId'])->toBe(SamlMetadataFixtures::ENTITY_ID)
        ->and($idp['singleSignOnService']['url'])->toBe(SamlMetadataFixtures::SSO_URL)
        ->and($idp['singleLogoutService']['url'])->toBe(SamlMetadataFixtures::SLO_URL)
        ->and(app('OneLogin_Saml2_Auth')->getSettings()->getSPData()['NameIDFormat'])
        ->toBe('urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress');
});

describe('this application\'s own certificate', function () {
    beforeEach(function () {
        $this->disk = fakeCertificateDisk();
        $this->provider = IdentityProvider::factory()->create([
            'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
            'name_id_format' => 'persistent',
        ]);
    });

    it('hands the toolkit whatever is on disk', function () {
        $keypair = SpCertificateFixtures::place('sp');

        $sp = spSettingsFor($this->provider);

        expect($sp['x509cert'])->toBe($keypair['certificate'])
            ->and($sp['privateKey'])->toBe($keypair['key']);
    });

    it('offers the rollover certificate as well, which is the only way it reaches the metadata document', function () {
        SpCertificateFixtures::place('sp');
        $secondary = SpCertificateFixtures::place('sp_new');

        idpSettingsFor($this->provider);

        expect(Certificate::normalise(app('OneLogin_Saml2_Auth')->getSettings()->getSPcertNew()))
            ->toBe(Certificate::normalise($secondary['certificate']));
    });

    it('leaves an environment-configured certificate alone when there is nothing on disk', function () {
        config(['saml2.sp.x509cert' => SamlMetadataFixtures::CERT_B, 'saml2.sp.privateKey' => 'a key from the environment']);

        $sp = spSettingsFor($this->provider);

        expect(Certificate::normalise($sp['x509cert']))->toBe(SamlMetadataFixtures::CERT_B);
    });

    it('will not offer half a pair', function () {
        $this->disk->put('certs/sp.crt', SpCertificateFixtures::keypair()['certificate']);

        $sp = spSettingsFor($this->provider);

        expect($sp['x509cert'])->toBe('');
    });
});

/*
| The floor this package puts under every application's `config/saml2.php`.
| That file is published into the consuming application, so a value fixed there
| reaches new installs only and is editable afterwards — these tests set the
| published config to the wrong thing on purpose and assert it does not win.
*/
describe('the security floor', function () {
    beforeEach(function () {
        $this->provider = IdentityProvider::factory()->create([
            'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
            'name_id_format' => 'persistent',
        ]);
    });

    it('requires the assertion itself to be signed, whatever the application says', function () {
        config(['saml2.security.wantAssertionsSigned' => false]);

        expect(securitySettingsFor($this->provider)['wantAssertionsSigned'])->toBeTrue();
    });

    it('keeps the toolkit strict, whatever the application says', function () {
        config(['saml2.strict' => false]);

        idpSettingsFor($this->provider);

        expect(app('OneLogin_Saml2_Auth')->getSettings()->isStrict())->toBeTrue();
    });

    it('keeps schema validation on and destination validation exact', function () {
        config([
            'saml2.security.wantXMLValidation' => false,
            'saml2.security.relaxDestinationValidation' => true,
        ]);

        $security = securitySettingsFor($this->provider);

        expect($security['wantXMLValidation'])->toBeTrue()
            ->and($security['relaxDestinationValidation'])->toBeFalse();
    });

    /*
    | Not forced: Entra ID signs the <samlp:Response> envelope only when the
    | enterprise application is switched to "Sign SAML response and assertion",
    | so demanding it here would break every integration that has not been.
    */
    it('leaves envelope signing to the application', function () {
        expect(securitySettingsFor($this->provider)['wantMessagesSigned'])->toBeFalse();
    });

    it('demands a signed envelope when the application asks for one', function () {
        config(['saml.security.want_messages_signed' => true]);

        expect(securitySettingsFor($this->provider)['wantMessagesSigned'])->toBeTrue();
    });

    it('refuses unsolicited responses out of the box', function () {
        expect(securitySettingsFor($this->provider)['rejectUnsolicitedResponsesWithInResponseTo'])->toBeTrue();
    });

    it('stands aside for an application that still needs IdP-initiated sign-in', function () {
        config(['saml.security.reject_unsolicited' => false]);

        expect(securitySettingsFor($this->provider)['rejectUnsolicitedResponsesWithInResponseTo'])->toBeFalse();
    });

    /*
    | The toolkit refuses a response carrying an InResponseTo whenever it was
    | given no request ID to match against, and it is only given one when the
    | binding is on. So this setting on its own would refuse every ordinary
    | Entra sign-in, which is why it cannot be set on its own.
    */
    it('will not refuse unsolicited responses while nothing binds the request', function () {
        config([
            'saml.security.reject_unsolicited' => true,
            'saml.security.strict_request_binding' => false,
        ]);

        expect(securitySettingsFor($this->provider)['rejectUnsolicitedResponsesWithInResponseTo'])->toBeFalse();
    });
});

describe('the request-binding switch', function () {
    it('is carried down to the vendor package, which is what implements it', function () {
        config(['saml.security.strict_request_binding' => true]);

        (new SsoServiceProvider(app()))->boot();

        expect(config('saml2.strictRequestBinding'))->toBeTrue();
    });

    it('is on by default, so login CSRF is closed without anybody opting in', function () {
        (new SsoServiceProvider(app()))->boot();

        expect(config('saml2.strictRequestBinding'))->toBeTrue();
    });

    /*
    | The escape hatch for an application still reached through the Entra "My
    | Apps" tile: a response nobody asked for has no InResponseTo to match.
    */
    it('can be switched off for an application that still needs IdP-initiated sign-in', function () {
        config(['saml.security.strict_request_binding' => false]);

        (new SsoServiceProvider(app()))->boot();

        expect(config('saml2.strictRequestBinding'))->toBeFalse();
    });
});

describe('the signing switches', function () {
    beforeEach(function () {
        $this->disk = fakeCertificateDisk();
        $this->provider = IdentityProvider::factory()->create([
            'idp_x509_cert' => SamlMetadataFixtures::CERT_A,
            'name_id_format' => 'persistent',
        ]);
    });

    it('is off unless an administrator says otherwise', function () {
        SpCertificateFixtures::place('sp');

        $security = securitySettingsFor($this->provider);

        expect($security['authnRequestsSigned'])->toBeFalse()
            ->and($security['logoutRequestSigned'])->toBeFalse()
            ->and($security['logoutResponseSigned'])->toBeFalse()
            ->and($security['signMetadata'])->toBeFalse();
    });

    it('signs every message this application sends, from one switch', function () {
        SpCertificateFixtures::place('sp');
        samlSettings(['sign_requests' => true]);

        $security = securitySettingsFor($this->provider);

        expect($security['authnRequestsSigned'])->toBeTrue()
            ->and($security['logoutRequestSigned'])->toBeTrue()
            ->and($security['logoutResponseSigned'])->toBeTrue()
            ->and($security['signMetadata'])->toBeFalse();
    });

    it('signs the metadata document separately', function () {
        SpCertificateFixtures::place('sp');
        samlSettings(['sign_metadata' => true]);

        $security = securitySettingsFor($this->provider);

        expect($security['signMetadata'])->toBeTrue()
            ->and($security['authnRequestsSigned'])->toBeFalse();
    });

    it('ignores the switches rather than taking every SAML route down with it', function () {
        samlSettings(['sign_requests' => true, 'sign_metadata' => true]);

        // The load-bearing assertion of the whole feature. The toolkit treats
        // "sign, but there is no key" as an invalid configuration and throws
        // from the constructor — inside the singleton every SAML route
        // resolves, metadata endpoint included.
        $security = securitySettingsFor($this->provider);

        expect($security['authnRequestsSigned'])->toBeFalse()
            ->and($security['logoutRequestSigned'])->toBeFalse()
            ->and($security['logoutResponseSigned'])->toBeFalse()
            ->and($security['signMetadata'])->toBeFalse();
    });

    it('honours the switches for a certificate configured through the environment', function () {
        $keypair = SpCertificateFixtures::keypair('from-the-environment.example.edu.au');
        config(['saml2.sp.x509cert' => $keypair['certificate'], 'saml2.sp.privateKey' => $keypair['key']]);
        samlSettings(['sign_requests' => true]);

        expect(securitySettingsFor($this->provider)['authnRequestsSigned'])->toBeTrue();
    });
});
