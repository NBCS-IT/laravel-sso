<?php

use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Support\Certificate;
use NBCSIT\Sso\Tests\Fixtures\SpCertificateFixtures;

/*
|--------------------------------------------------------------------------
| The SP metadata document, end to end
|--------------------------------------------------------------------------
|
| This is the only test that crosses every boundary at once: the package's
| builder, the vendor package's route and controller, and the toolkit's own
| metadata builder and XSD validation. Everything else in this feature is a unit
| of something; this is the proof that the units are wired to each other.
|
| It is also where a rollover is actually visible. `x509certNew` is not a key
| the vendor config has ever had, and the toolkit reads it in exactly one place
| — `Settings::getSPMetadata()` — so the only way to know it arrived is to fetch
| the document and count the KeyDescriptors.
|
*/

function metadataFor(IdentityProvider $provider): string
{
    return (string) test()->get(route('saml.metadata', ['uuid' => $provider->uuid]))
        ->assertOk()
        ->getContent();
}

/**
 * @return array<int, SimpleXMLElement>
 */
function signingKeyDescriptors(string $xml): array
{
    $document = simplexml_load_string($xml);
    $document->registerXPathNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');

    return $document->xpath('//md:SPSSODescriptor/md:KeyDescriptor[@use="signing"]') ?: [];
}

beforeEach(function () {
    $this->provider = IdentityProvider::factory()->create();
});

it('publishes nothing rather than failing when no certificate has been generated', function () {
    fakeCertificateDisk();

    expect(signingKeyDescriptors(metadataFor($this->provider)))->toBeEmpty();
});

it('publishes the certificate in use', function () {
    fakeCertificateDisk();
    $primary = SpCertificateFixtures::place('sp');

    $xml = metadataFor($this->provider);

    expect(signingKeyDescriptors($xml))->toHaveCount(1)
        ->and($xml)->toContain(Certificate::normalise($primary['certificate']));
});

it('publishes both certificates during a rollover, so the provider can import the next one early', function () {
    fakeCertificateDisk();
    $primary = SpCertificateFixtures::place('sp');
    $secondary = SpCertificateFixtures::place('sp_new');

    $xml = metadataFor($this->provider);

    expect(signingKeyDescriptors($xml))->toHaveCount(2)
        ->and($xml)->toContain(Certificate::normalise($primary['certificate']))
        ->and($xml)->toContain(Certificate::normalise($secondary['certificate']));
});

it('signs the document when asked to, and the signed document still validates', function () {
    fakeCertificateDisk();
    SpCertificateFixtures::place('sp');
    samlSettings(['sign_metadata' => true]);

    // `getMetadata()` runs the toolkit's own XSD validation and throws if the
    // document it just signed no longer parses, so a 200 here is the assertion
    // that matters most.
    expect(metadataFor($this->provider))->toContain('<ds:Signature');
});

it('leaves the document unsigned by default', function () {
    fakeCertificateDisk();
    SpCertificateFixtures::place('sp');

    expect(metadataFor($this->provider))->not->toContain('<ds:Signature');
});

it('does not sign, and does not fail, when signing is on but no certificate exists', function () {
    fakeCertificateDisk();
    samlSettings(['sign_metadata' => true, 'sign_requests' => true]);

    $xml = metadataFor($this->provider);

    expect($xml)->not->toContain('<ds:Signature')
        ->and(signingKeyDescriptors($xml))->toBeEmpty();
});
