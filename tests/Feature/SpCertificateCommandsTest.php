<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use NBCSIT\Sso\Certificates\SpCertificateStore;
use NBCSIT\Sso\Tests\Fixtures\SpCertificateFixtures;

beforeEach(function () {
    $this->disk = fakeCertificateDisk();
});

describe('saml:generate-certificate', function () {
    it('generates the rollover certificate by default, which is the half that changes nothing', function () {
        $this->artisan('saml:generate-certificate')
            ->expectsOutputToContain('Generated a rollover certificate')
            ->assertSuccessful();

        expect($this->disk->exists('certs/sp_new.crt'))->toBeTrue()
            ->and($this->disk->exists('certs/sp_new.key'))->toBeTrue()
            ->and($this->disk->exists('certs/sp.crt'))->toBeFalse();
    });

    it('says when there is nothing yet for a rollover certificate to roll over from', function () {
        $this->artisan('saml:generate-certificate')
            ->expectsOutputToContain('nothing is signing yet')
            ->assertSuccessful();
    });

    it('writes the certificate in use when there is none', function () {
        $this->artisan('saml:generate-certificate --primary')->assertSuccessful();

        expect(app(SpCertificateStore::class)->canSign())->toBeTrue();
    });

    it('asks before replacing the certificate in use', function () {
        $before = SpCertificateFixtures::place('sp');

        $this->artisan('saml:generate-certificate --primary')
            ->expectsConfirmation('Replace the certificate in use now? Every signature the identity provider is validating stops being valid the moment this is written.', 'yes')
            ->assertSuccessful();

        expect($this->disk->get('certs/sp.crt'))->not->toBe($before['certificate']);
    });

    it('leaves the certificate in use alone when the answer is no, and names the safe path', function () {
        $before = SpCertificateFixtures::place('sp');

        $this->artisan('saml:generate-certificate --primary')
            ->expectsConfirmation('Replace the certificate in use now? Every signature the identity provider is validating stops being valid the moment this is written.', 'no')
            ->expectsOutputToContain('saml:promote-certificate')
            ->assertFailed();

        expect($this->disk->get('certs/sp.crt'))->toBe($before['certificate']);
    });

    it('replaces the certificate in use without asking when forced, for a deploy script', function () {
        $before = SpCertificateFixtures::place('sp');

        $this->artisan('saml:generate-certificate --primary --force')->assertSuccessful();

        expect($this->disk->get('certs/sp.crt'))->not->toBe($before['certificate']);
    });

    it('takes the validity period and the common name from its options', function () {
        $this->artisan('saml:generate-certificate --days=30 --cn=chosen.example.edu.au')->assertSuccessful();

        $certificate = app(SpCertificateStore::class)->pair()->secondary;

        expect($certificate->subject)->toContain('chosen.example.edu.au')
            ->and($certificate->expiresAt->isBefore(now()->addDays(31)))->toBeTrue();
    });

    it('takes the key size from its options', function () {
        $this->artisan('saml:generate-certificate --bits=3072')->assertSuccessful();

        $details = openssl_pkey_get_details(openssl_pkey_get_private((string) $this->disk->get('certs/sp_new.key')));

        expect($details['bits'])->toBe(3072);
    });

    it('fails, rather than half-succeeding, when the store refuses', function () {
        $this->artisan('saml:generate-certificate --days=0')
            ->expectsOutputToContain('between 1 and 7300 days')
            ->assertFailed();
    });

    it('shows both slots so the state of a rollover is readable at a glance', function () {
        SpCertificateFixtures::place('sp');

        $this->artisan('saml:generate-certificate')
            ->expectsOutputToContain('In use now')
            ->expectsOutputToContain('Rollover')
            ->assertSuccessful();
    });
});

describe('saml:promote-certificate', function () {
    it('swaps the rollover certificate into use', function () {
        SpCertificateFixtures::place('sp');
        $secondary = SpCertificateFixtures::place('sp_new');

        $this->artisan('saml:promote-certificate')
            ->expectsOutputToContain('Promoted the rollover certificate')
            ->assertSuccessful();

        expect($this->disk->get('certs/sp.crt'))->toBe($secondary['certificate']);
    });

    it('fails when there is nothing to promote, and says what to run instead', function () {
        $this->artisan('saml:promote-certificate')
            ->expectsOutputToContain('saml:generate-certificate')
            ->assertFailed();
    });
});

describe('registration', function () {
    it('registers both commands under names that are the package\'s to take', function () {
        expect(array_keys(app(Kernel::class)->all()))
            ->toContain('saml:generate-certificate')
            ->toContain('saml:promote-certificate');
    });

    it('schedules neither, because a certificate nobody asked for is one the provider has never seen', function () {
        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? '')
            ->implode(' ');

        expect($scheduled)->not->toContain('saml:generate-certificate')
            ->and($scheduled)->not->toContain('saml:promote-certificate');
    });
});
