<?php

use Illuminate\Support\Carbon;
use NBCSIT\Sso\Metadata\IdpMetadataReader;
use NBCSIT\Sso\Support\MetadataUnreadable;
use NBCSIT\Sso\Tests\Fixtures\SamlMetadataFixtures;

beforeEach(function () {
    $this->reader = new IdpMetadataReader;
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('reading a document', function () {
    it('takes the entity ID, the endpoints and the certificates', function () {
        $metadata = $this->reader->read(SamlMetadataFixtures::document());

        expect($metadata->entityId)->toBe(SamlMetadataFixtures::ENTITY_ID)
            ->and($metadata->ssoUrl)->toBe(SamlMetadataFixtures::SSO_URL)
            ->and($metadata->sloUrl)->toBe(SamlMetadataFixtures::SLO_URL)
            ->and($metadata->certificateBodies())->toBe([SamlMetadataFixtures::CERT_A])
            ->and($metadata->warnings)->toBe([]);
    });

    it('stores the NameID format in the short form the tenant column wants', function () {
        // The package puts the URN prefix back when it configures the toolkit,
        // so storing the whole URN would produce a doubled one.
        expect($this->reader->read(SamlMetadataFixtures::document())->nameIdFormat)->toBe('persistent');
    });

    it('leaves the NameID format unset when the document names none', function () {
        expect($this->reader->read(SamlMetadataFixtures::document(nameIdFormat: null))->nameIdFormat)->toBeNull();
    });

    it('keeps every signing certificate a rollover publishes', function () {
        $metadata = $this->reader->read(SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ));

        expect($metadata->certificateBodies())->toBe([SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B])
            ->and($metadata->primaryCertificate()->thumbprint)->toBe(SamlMetadataFixtures::THUMBPRINT_A);
    });

    it('reads the document\'s expiry date', function () {
        $metadata = $this->reader->read(SamlMetadataFixtures::document(validUntil: '2099-01-01T00:00:00Z'));

        expect($metadata->validUntil?->toDateString())->toBe('2099-01-01')
            ->and($metadata->warnings)->toBe([]);
    });

    it('picks the named provider out of a federation file', function () {
        $xml = SamlMetadataFixtures::federation(['https://one.example.edu.au', 'https://two.example.edu.au']);

        $metadata = $this->reader->read($xml, 'https://two.example.edu.au');

        expect($metadata->entityId)->toBe('https://two.example.edu.au')
            ->and($metadata->ssoUrl)->toBe('https://two.example.edu.au/sso');
    });
});

describe('the fingerprint', function () {
    it('is the same for two documents that differ only in what is ignored', function () {
        $first = $this->reader->read(SamlMetadataFixtures::document(validUntil: '2030-01-01T00:00:00Z'));
        $second = $this->reader->read(SamlMetadataFixtures::document(validUntil: '2031-06-01T00:00:00Z'));

        expect($first->fingerprint())->toBe($second->fingerprint());
    });

    it('ignores the order the certificates are published in', function () {
        $forwards = $this->reader->read(SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ));
        $backwards = $this->reader->read(SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_B, SamlMetadataFixtures::CERT_A],
        ));

        expect($forwards->fingerprint())->toBe($backwards->fingerprint());
    });

    it('moves when a certificate does', function () {
        $before = $this->reader->read(SamlMetadataFixtures::document());
        $after = $this->reader->read(SamlMetadataFixtures::document(
            certificates: [SamlMetadataFixtures::CERT_A, SamlMetadataFixtures::CERT_B],
        ));

        expect($before->fingerprint())->not->toBe($after->fingerprint());
    });
});

describe('warnings', function () {
    it('says which provider it took out of a file describing several', function () {
        $xml = SamlMetadataFixtures::federation(['https://one.example.edu.au', 'https://two.example.edu.au']);

        expect($this->reader->read($xml)->warnings)
            ->toHaveCount(1)
            ->and($this->reader->read($xml)->warnings[0])
            ->toContain('describes 2 providers')
            ->toContain('https://one.example.edu.au');
    });

    it('says when the sign-in endpoint is not published for the binding in use', function () {
        $metadata = $this->reader->read(SamlMetadataFixtures::document(ssoBinding: SamlMetadataFixtures::POST_BINDING));

        expect($metadata->warnings[0])->toContain('HTTP-POST')->toContain('HTTP-Redirect');
    });

    it('says when the sign-in endpoint states no binding at all', function () {
        $metadata = $this->reader->read(SamlMetadataFixtures::document(ssoBinding: ''));

        expect($metadata->warnings[0])->toContain('no stated binding');
    });

    it('says when there is nowhere to sign out', function () {
        $metadata = $this->reader->read(SamlMetadataFixtures::document(sloUrl: null));

        expect($metadata->sloUrl)->toBeNull()
            ->and($metadata->warnings[0])->toContain('SingleLogoutService');
    });

    it('says when the document itself has expired', function () {
        $metadata = $this->reader->read(SamlMetadataFixtures::document(validUntil: '2020-03-04T00:00:00Z'));

        expect($metadata->warnings[0])->toContain('expired on 4 Mar 2020');
    });

    it('takes the earlier of the two expiry dates a document can carry', function () {
        $xml = str_replace(
            '<IDPSSODescriptor',
            '<IDPSSODescriptor validUntil="2019-01-01T00:00:00Z"',
            SamlMetadataFixtures::document(validUntil: '2020-03-04T00:00:00Z'),
        );

        expect($this->reader->read($xml)->validUntil?->toDateString())->toBe('2019-01-01');
    });

    it('ignores an expiry date it cannot make sense of', function () {
        $metadata = $this->reader->read(SamlMetadataFixtures::document(validUntil: 'the day after tomorrow'));

        expect($metadata->validUntil)->toBeNull()
            ->and($metadata->warnings)->toBe([]);
    });

    it('says when a signing certificate has already expired', function () {
        Carbon::setTestNow('2040-01-01');

        $metadata = $this->reader->read(SamlMetadataFixtures::document());

        expect($metadata->warnings[0])->toContain('already expired')
            ->toContain(SamlMetadataFixtures::THUMBPRINT_A);
    });
});

describe('documents it will not read', function () {
    it('refuses an empty one', function () {
        expect(fn () => $this->reader->read('   '))
            ->toThrow(MetadataUnreadable::class, 'That metadata document is empty.');
    });

    it('refuses one that is not XML', function () {
        expect(fn () => $this->reader->read('{"format":"campus-map-layer"}'))
            ->toThrow(MetadataUnreadable::class, 'could not be read as SAML metadata');
    });

    it('refuses one carrying a DOCTYPE, which is the XXE guard', function () {
        $xml = '<?xml version="1.0"?><!DOCTYPE EntityDescriptor>'.substr(SamlMetadataFixtures::document(), 38);

        expect(fn () => $this->reader->read($xml))
            ->toThrow(MetadataUnreadable::class, 'DOCTYPE');
    });

    it('refuses service provider metadata', function () {
        expect(fn () => $this->reader->read(SamlMetadataFixtures::serviceProviderDocument()))
            ->toThrow(MetadataUnreadable::class, 'no IDPSSODescriptor');
    });

    it('refuses a federation file with no such entity ID', function () {
        $xml = SamlMetadataFixtures::federation(['https://one.example.edu.au']);

        expect(fn () => $this->reader->read($xml, 'https://nobody.example.edu.au'))
            ->toThrow(MetadataUnreadable::class, 'no identity provider with the entity ID');
    });

    it('refuses a provider with no entityID', function () {
        $xml = '<?xml version="1.0"?>'
            .'<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata">'
            .'<IDPSSODescriptor protocolSupportEnumeration="p">'
            .'<SingleSignOnService Binding="'.SamlMetadataFixtures::REDIRECT_BINDING.'" Location="https://x/sso"/>'
            .'</IDPSSODescriptor></EntityDescriptor>';

        expect(fn () => $this->reader->read($xml))
            ->toThrow(MetadataUnreadable::class, 'no entityID');
    });

    it('refuses a provider with no sign-in endpoint', function () {
        $xml = '<?xml version="1.0"?>'
            .'<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://x">'
            .'<IDPSSODescriptor protocolSupportEnumeration="p"/></EntityDescriptor>';

        expect(fn () => $this->reader->read($xml))
            ->toThrow(MetadataUnreadable::class, 'no SingleSignOnService endpoint');
    });

    it('refuses a provider with no signing certificate', function () {
        expect(fn () => $this->reader->read(SamlMetadataFixtures::document(certificates: [])))
            ->toThrow(MetadataUnreadable::class, 'no signing certificate');
    });
});
