<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use NBCSIT\Sso\Certificates\SpCertificateStore;
use NBCSIT\Sso\Tests\Fixtures\SpCertificateFixtures;

beforeEach(function () {
    $this->disk = fakeCertificateDisk();
    $this->store = app(SpCertificateStore::class);
});

/**
 * Everything on the certificate disk, so a test can prove a refusal changed
 * nothing rather than merely reporting that it had not.
 *
 * @return array<string, string>
 */
function certificateDiskContents(Filesystem $disk): array
{
    $contents = [];

    foreach ($disk->allFiles('certs') as $file) {
        $contents[$file] = (string) $disk->get($file);
    }

    return $contents;
}

describe('reading what is there', function () {
    it('is inert on an empty disk rather than throwing', function () {
        expect($this->store->pair()->primary)->toBeNull()
            ->and($this->store->pair()->secondary)->toBeNull()
            ->and($this->store->pair()->usable)->toBeFalse()
            ->and($this->store->canSign())->toBeFalse()
            ->and($this->store->primaryCertificate())->toBeNull()
            ->and($this->store->primaryKey())->toBeNull()
            ->and($this->store->secondaryCertificate())->toBeNull();
    });

    it('reads a certificate and its key back as the pair that was written', function () {
        $keypair = SpCertificateFixtures::place('sp');

        expect($this->store->primaryCertificate())->toBe($keypair['certificate'])
            ->and($this->store->primaryKey())->toBe($keypair['key'])
            ->and($this->store->canSign())->toBeTrue();
    });

    it('will not sign with a certificate whose key is missing', function () {
        $this->disk->put('certs/sp.crt', SpCertificateFixtures::keypair()['certificate']);

        expect($this->store->pair()->primary)->not->toBeNull()
            ->and($this->store->canSign())->toBeFalse();
    });

    it('will not sign with a key that did not sign the certificate', function () {
        SpCertificateFixtures::placeMismatched('sp');

        expect($this->store->canSign())->toBeFalse();
    });

    it('still describes a certificate it cannot parse, so a half-written file is visible', function () {
        $this->disk->put('certs/sp.crt', 'this is not a certificate');
        $this->disk->put('certs/sp.key', SpCertificateFixtures::keypair()['key']);

        expect($this->store->pair()->primary->describe())->toContain('could not be read')
            ->and($this->store->canSign())->toBeFalse();
    });

    it('treats an empty file as no file', function () {
        $this->disk->put('certs/sp.crt', "  \n");

        expect($this->store->primaryCertificate())->toBeNull();
    });

    it('follows the configured disk and path rather than capturing them', function () {
        SpCertificateFixtures::place('sp');

        config(['saml.certificate.path' => 'somewhere-else']);

        expect($this->store->primaryCertificate())->toBeNull();
    });

    it('copes with a path configured as the root of the disk', function () {
        config(['saml.certificate.path' => '']);
        $this->disk->put('sp.crt', SpCertificateFixtures::keypair()['certificate']);

        expect($this->store->primaryCertificate())->not->toBeNull();
    });
});

describe('generating', function () {
    it('writes a rollover certificate and leaves the one in use alone', function () {
        $primary = SpCertificateFixtures::place('sp');

        $report = $this->store->generateSecondary();

        expect($report->succeeded())->toBeTrue()
            ->and($report->message)->toContain('Generated a rollover certificate')
            ->and($this->disk->get('certs/sp.crt'))->toBe($primary['certificate'])
            ->and($this->disk->get('certs/sp.key'))->toBe($primary['key'])
            ->and($this->store->secondaryCertificate())->not->toBeNull();
    });

    it('says so when a rollover certificate has nothing to roll over from', function () {
        $report = $this->store->generateSecondary();

        expect($report->succeeded())->toBeTrue()
            ->and($report->warnings)->toHaveCount(1)
            ->and($report->warnings[0])->toContain('nothing is signing yet');
    });

    it('writes a primary certificate onto an empty disk', function () {
        $report = $this->store->generatePrimary();

        expect($report->succeeded())->toBeTrue()
            ->and($report->message)->toContain('Import it at the identity provider now')
            ->and($this->store->canSign())->toBeTrue();
    });

    it('refuses to overwrite the certificate in use, and names the safe path instead', function () {
        SpCertificateFixtures::place('sp');
        $before = certificateDiskContents($this->disk);

        $report = $this->store->generatePrimary();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('Generate a rollover certificate instead')
            ->and(certificateDiskContents($this->disk))->toBe($before);
    });

    it('overwrites the certificate in use when told to in as many words', function () {
        $before = SpCertificateFixtures::place('sp');

        $report = $this->store->generatePrimary(force: true);

        expect($report->succeeded())->toBeTrue()
            ->and($this->disk->get('certs/sp.crt'))->not->toBe($before['certificate'])
            ->and($this->store->canSign())->toBeTrue();
    });

    it('passes the common name through to the certificate', function () {
        $this->store->generateSecondary(commonName: 'chosen.example.edu.au');

        expect($this->store->pair()->secondary->subject)->toContain('chosen.example.edu.au');
    });

    it('takes the validity period from config when it is not given one', function () {
        config(['saml.certificate.days' => 30]);

        $this->store->generateSecondary();

        expect($this->store->pair()->secondary->expiresAt->isBefore(now()->addDays(31)))->toBeTrue();
    });

    it('refuses a validity period outside what it is willing to mint', function () {
        $report = $this->store->generateSecondary(days: 0);

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('between 1 and 7300 days')
            ->and(certificateDiskContents($this->disk))->toBe([]);
    });

    it('reports openssl refusing, rather than letting the exception out', function () {
        $report = $this->store->generateSecondary(bits: 64);

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('64-bit RSA key could not be generated');
    });

    it('refuses to write a private key to a disk that is served over HTTP', function () {
        config(['filesystems.disks.saml-certificates.url' => 'https://example.edu.au/storage']);

        $report = $this->store->generateSecondary();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('served over HTTP')
            ->and(certificateDiskContents($this->disk))->toBe([]);
    });

    it('reports a disk it cannot write to', function () {
        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('get')->andReturn(null);
        $failing->shouldReceive('put')->andReturn(false);
        Storage::set('saml-certificates', $failing);

        $report = $this->store->generateSecondary();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('could not be written to `certs/sp_new.crt`');
    });
});

describe('promoting', function () {
    it('swaps the two slots, keeping the demoted certificate published', function () {
        $primary = SpCertificateFixtures::place('sp');
        $secondary = SpCertificateFixtures::place('sp_new');

        $report = $this->store->promote();

        expect($report->succeeded())->toBeTrue()
            ->and($this->disk->get('certs/sp.crt'))->toBe($secondary['certificate'])
            ->and($this->disk->get('certs/sp.key'))->toBe($secondary['key'])
            ->and($this->disk->get('certs/sp_new.crt'))->toBe($primary['certificate'])
            ->and($this->disk->get('certs/sp_new.key'))->toBe($primary['key'])
            ->and($this->store->canSign())->toBeTrue();
    });

    it('keeps a copy of what it replaced', function () {
        $primary = SpCertificateFixtures::place('sp');
        SpCertificateFixtures::place('sp_new');

        $this->store->promote();

        expect($this->disk->get('certs/sp_previous.crt'))->toBe($primary['certificate'])
            ->and($this->disk->get('certs/sp_previous.key'))->toBe($primary['key']);
    });

    it('is its own undo', function () {
        SpCertificateFixtures::place('sp');
        SpCertificateFixtures::place('sp_new');
        $before = certificateDiskContents($this->disk);

        $this->store->promote();
        $this->store->promote();

        expect(collect(certificateDiskContents($this->disk))->only(array_keys($before))->all())->toBe($before);
    });

    it('takes the rollover certificate into use when there is nothing to demote', function () {
        $secondary = SpCertificateFixtures::place('sp_new');

        $report = $this->store->promote();

        expect($report->succeeded())->toBeTrue()
            ->and($report->message)->toContain('now has a certificate to sign with')
            ->and($this->disk->get('certs/sp.crt'))->toBe($secondary['certificate'])
            ->and($this->disk->exists('certs/sp_new.crt'))->toBeFalse()
            ->and($this->disk->exists('certs/sp_new.key'))->toBeFalse()
            ->and($this->disk->exists('certs/sp_previous.crt'))->toBeFalse();
    });

    it('refuses when there is nothing to promote', function () {
        SpCertificateFixtures::place('sp');
        $before = certificateDiskContents($this->disk);

        $report = $this->store->promote();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('no rollover certificate to promote')
            ->and(certificateDiskContents($this->disk))->toBe($before);
    });

    it('refuses half a rollover pair', function () {
        SpCertificateFixtures::place('sp');
        $this->disk->put('certs/sp_new.crt', SpCertificateFixtures::keypair('lonely.example.edu.au')['certificate']);
        $before = certificateDiskContents($this->disk);

        $report = $this->store->promote();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('no rollover certificate to promote')
            ->and(certificateDiskContents($this->disk))->toBe($before);
    });

    it('refuses a certificate and key that do not belong to each other', function () {
        SpCertificateFixtures::place('sp');
        SpCertificateFixtures::placeMismatched('sp_new');
        $before = certificateDiskContents($this->disk);

        $report = $this->store->promote();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('do not belong to each other')
            ->and(certificateDiskContents($this->disk))->toBe($before);
    });

    it('refuses to promote onto a disk that is served over HTTP', function () {
        SpCertificateFixtures::place('sp_new');
        config(['filesystems.disks.saml-certificates.url' => 'https://example.edu.au/storage']);

        expect($this->store->promote()->message)->toContain('served over HTTP');
    });

    it('names the recovery copy when a write fails half way through', function () {
        SpCertificateFixtures::place('sp');
        SpCertificateFixtures::place('sp_new');
        $real = Storage::disk('saml-certificates');

        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path));
        $failing->shouldReceive('put')->andReturn(false);
        Storage::set('saml-certificates', $failing);

        $report = $this->store->promote();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('certs/sp_previous.crt')
            ->and($report->message)->toContain('copy them back over');
    });

    it('reports a failure to write the promoted certificate itself', function () {
        SpCertificateFixtures::place('sp');
        SpCertificateFixtures::place('sp_new');
        $real = Storage::disk('saml-certificates');
        $writes = 0;

        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path));

        // The two writes of the recovery copy land; the promoted key does not.
        $failing->shouldReceive('put')->andReturnUsing(function () use (&$writes) {
            return ++$writes <= 2;
        });
        Storage::set('saml-certificates', $failing);

        $report = $this->store->promote();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('while writing the promoted certificate');
    });

    it('reports a failure to swap the demoted certificate back into the rollover slot', function () {
        SpCertificateFixtures::place('sp');
        SpCertificateFixtures::place('sp_new');
        $real = Storage::disk('saml-certificates');
        $writes = 0;

        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path));
        $failing->shouldReceive('put')->andReturnUsing(function () use (&$writes) {
            // The copy, then the promoted key and certificate, then the swap
            // back — which is the one that fails.
            return ++$writes <= 4;
        });
        Storage::set('saml-certificates', $failing);

        $report = $this->store->promote();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('into the rollover slot');
    });

    it('reports a failure to clear the rollover slot when there was nothing to demote', function () {
        SpCertificateFixtures::place('sp_new');
        $real = Storage::disk('saml-certificates');

        $failing = Mockery::mock(Filesystem::class);
        $failing->shouldReceive('get')->andReturnUsing(fn (string $path) => $real->get($path));
        $failing->shouldReceive('put')->andReturn(true);
        $failing->shouldReceive('delete')->andReturn(false);
        Storage::set('saml-certificates', $failing);

        expect($this->store->promote()->message)->toContain('into the rollover slot');
    });
});

describe('one at a time', function () {
    it('refuses to act while somebody else is holding the lock', function () {
        SpCertificateFixtures::place('sp_new');
        Cache::lock('saml:sp-certificate', 30)->acquire();

        $report = $this->store->promote();

        expect($report->succeeded())->toBeFalse()
            ->and($report->message)->toContain('Somebody else is generating or promoting a certificate');
    });
});
