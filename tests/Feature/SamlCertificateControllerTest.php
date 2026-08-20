<?php

use NBCSIT\Sso\Certificates\SpCertificateStore;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Settings\SamlSettings;
use NBCSIT\Sso\Tests\Fixtures\SpCertificateFixtures;

beforeEach(function () {
    $this->disk = fakeCertificateDisk();
    $this->admin = userWithPermissions(config('saml.gate'));
});

describe('authorisation', function () {
    it('refuses somebody who holds a different ability entirely', function () {
        $stranger = userWithPermissions('some other ability');

        $this->actingAs($stranger)->get(route('admin.settings.saml.certificate.show'))->assertForbidden();
        $this->actingAs($stranger)->post(route('admin.settings.saml.certificate.store'))->assertForbidden();
        $this->actingAs($stranger)->post(route('admin.settings.saml.certificate.promote'))->assertForbidden();
        $this->actingAs($stranger)->put(route('admin.settings.saml.certificate.signing.update'))->assertForbidden();
    });
});

describe('the screen', function () {
    it('says there is nothing to sign with before anything has been generated', function () {
        $this->actingAs($this->admin)->get(route('admin.settings.saml.certificate.show'))
            ->assertOk()
            ->assertSee('Nothing to sign with')
            ->assertSee('No certificate has been generated yet.')
            ->assertSee('There is no rollover certificate.');
    });

    it('shows both certificates, and where the provider reads them from', function () {
        SpCertificateFixtures::place('sp');
        SpCertificateFixtures::place('sp_new');
        $provider = IdentityProvider::factory()->create(['key' => 'Staff Entra ID']);

        $this->actingAs($this->admin)->get(route('admin.settings.saml.certificate.show'))
            ->assertOk()
            ->assertSee('In use now')
            ->assertSee('Rollover certificate')
            ->assertSee('Promote it')
            ->assertSee('Staff Entra ID')
            ->assertSee($provider->uuid);
    });

    it('says so when signing is switched on but cannot take effect', function () {
        samlSettings(['sign_requests' => true]);

        $this->actingAs($this->admin)->get(route('admin.settings.saml.certificate.show'))
            ->assertOk()
            ->assertSee('Signing is switched on, but not in effect');
    });

    it('says nothing about a rollover that does not exist', function () {
        SpCertificateFixtures::place('sp');

        $this->actingAs($this->admin)->get(route('admin.settings.saml.certificate.show'))
            ->assertOk()
            ->assertDontSee('Promote it');
    });

    it('is reachable from the single sign-on screen', function () {
        $this->actingAs($this->admin)->get(route('admin.settings.saml.edit'))
            ->assertOk()
            ->assertSee(route('admin.settings.saml.certificate.show'));
    });
});

describe('generating', function () {
    it('generates a rollover certificate', function () {
        $this->actingAs($this->admin)
            ->from(route('admin.settings.saml.certificate.show'))
            ->post(route('admin.settings.saml.certificate.store'), ['slot' => 'secondary'])
            ->assertRedirect(route('admin.settings.saml.certificate.show'))
            ->assertSessionHas('status');

        expect($this->disk->exists('certs/sp_new.crt'))->toBeTrue();
    });

    it('passes a validity period through', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.certificate.store'), ['slot' => 'secondary', 'days' => 30]);

        expect(app(SpCertificateStore::class)->pair()->secondary->expiresAt->isBefore(now()->addDays(31)))->toBeTrue();
    });

    it('refuses to replace the certificate in use unless the confirmation was typed', function () {
        $before = SpCertificateFixtures::place('sp');

        $this->actingAs($this->admin)
            ->from(route('admin.settings.saml.certificate.show'))
            ->post(route('admin.settings.saml.certificate.store'), ['slot' => 'primary'])
            ->assertSessionHasErrors('confirm');

        expect($this->disk->get('certs/sp.crt'))->toBe($before['certificate']);
    });

    it('refuses a confirmation that is not the word asked for', function () {
        SpCertificateFixtures::place('sp');

        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.certificate.store'), ['slot' => 'primary', 'confirm' => 'yes'])
            ->assertSessionHasErrors('confirm');
    });

    it('replaces the certificate in use once it has been', function () {
        $before = SpCertificateFixtures::place('sp');

        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.certificate.store'), ['slot' => 'primary', 'confirm' => 'replace'])
            ->assertSessionHas('status');

        expect($this->disk->get('certs/sp.crt'))->not->toBe($before['certificate']);
    });

    it('refuses a slot it does not have', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.certificate.store'), ['slot' => 'tertiary'])
            ->assertSessionHasErrors('slot');
    });

    it('flashes the failure rather than the success when the store refuses', function () {
        config(['filesystems.disks.saml-certificates.url' => 'https://example.edu.au/storage']);

        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.certificate.store'), ['slot' => 'secondary'])
            ->assertSessionHas('error');
    });
});

describe('promoting', function () {
    it('promotes the rollover certificate', function () {
        SpCertificateFixtures::place('sp');
        $secondary = SpCertificateFixtures::place('sp_new');

        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.certificate.promote'))
            ->assertSessionHas('status');

        expect($this->disk->get('certs/sp.crt'))->toBe($secondary['certificate']);
    });

    it('says why when there is nothing to promote', function () {
        $this->actingAs($this->admin)
            ->post(route('admin.settings.saml.certificate.promote'))
            ->assertSessionHas('error');
    });
});

describe('the signing switches', function () {
    it('saves both', function () {
        SpCertificateFixtures::place('sp');

        $this->actingAs($this->admin)
            ->put(route('admin.settings.saml.certificate.signing.update'), [
                'sign_requests' => '1',
                'sign_metadata' => '1',
            ])
            ->assertSessionHas('status');

        $settings = app(SamlSettings::class);

        expect($settings->sign_requests)->toBeTrue()
            ->and($settings->sign_metadata)->toBeTrue();
    });

    it('switches them off again', function () {
        SpCertificateFixtures::place('sp');
        samlSettings(['sign_requests' => true, 'sign_metadata' => true]);

        $this->actingAs($this->admin)
            ->put(route('admin.settings.saml.certificate.signing.update'), [
                'sign_requests' => '0',
                'sign_metadata' => '0',
            ])
            ->assertSessionHas('status');

        expect(app(SamlSettings::class)->sign_requests)->toBeFalse();
    });

    it('refuses to switch signing on with nothing to sign with, rather than saving a switch that is ignored', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.saml.certificate.signing.update'), ['sign_requests' => '1'])
            ->assertSessionHas('error');

        expect(app(SamlSettings::class)->sign_requests)->toBeFalse();
    });

    it('refuses metadata signing on the same grounds', function () {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.saml.certificate.signing.update'), ['sign_metadata' => '1'])
            ->assertSessionHas('error');

        expect(app(SamlSettings::class)->sign_metadata)->toBeFalse();
    });
});
