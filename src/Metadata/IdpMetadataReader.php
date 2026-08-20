<?php

namespace NBCSIT\Sso\Metadata;

use DOMDocument;
use DOMElement;
use Exception;
use Illuminate\Support\Carbon;
use NBCSIT\Sso\Support\Certificate;
use NBCSIT\Sso\Support\IdpMetadata;
use NBCSIT\Sso\Support\MetadataUnreadable;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use OneLogin\Saml2\Utils;
use Throwable;

/**
 * A SAML metadata document, read down to the handful of values this application
 * configures a provider from.
 *
 * The parsing itself is the toolkit's — `IdPMetadataParser` already handles the
 * namespaces, the binding preference and the certificate extraction, and it is
 * the same library that will later validate assertions, so agreeing with it is
 * the point. What this class adds is everything the toolkit's parser leaves out
 * and an administrator needs:
 *
 * - **The `validUntil` date.** A document that expired in March explains a login
 *   that stopped working in March.
 * - **A count of the providers in the file.** Federation metadata containing
 *   forty entities parses fine and silently configures whichever one is first.
 * - **The NameID format in the shape the tenant column wants**, which is the
 *   short suffix and not the full URN. The package re-prefixes it on the way
 *   out, so storing the URN produces a doubled one.
 * - **Warnings instead of refusals** wherever the document is usable but odd.
 */
class IdpMetadataReader
{
    /**
     * @throws MetadataUnreadable
     */
    public function read(string $xml, ?string $entityId = null): IdpMetadata
    {
        // Without this libxml raises a PHP warning for every malformed
        // document, and a malformed document is an ordinary thing for someone
        // to upload. What was wrong with it comes back as MetadataUnreadable.
        $previous = libxml_use_internal_errors(true);

        try {
            return $this->parse(trim($xml), $entityId);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @throws MetadataUnreadable
     */
    private function parse(string $xml, ?string $entityId): IdpMetadata
    {
        if ($xml === '') {
            throw new MetadataUnreadable('That metadata document is empty.');
        }

        try {
            $parsed = IdPMetadataParser::parseXML($xml, $entityId);
        } catch (Exception $exception) {
            // The toolkit's message names DOCTYPE rejection and malformed XML,
            // both of which are worth passing on verbatim.
            throw new MetadataUnreadable('That file could not be read as SAML metadata. '.$exception->getMessage());
        }

        $idp = $parsed['idp'] ?? null;

        if (! is_array($idp)) {
            throw new MetadataUnreadable(
                $entityId === null
                    ? 'That document has no IDPSSODescriptor, so it does not describe an identity provider. A service provider\'s metadata — this application\'s own, for instance — reads like this.'
                    : 'That document has no identity provider with the entity ID "'.$entityId.'".',
            );
        }

        if (! isset($idp['entityId'])) {
            throw new MetadataUnreadable('That provider has no entityID, so there is nothing to identify it by.');
        }

        $ssoUrl = $idp['singleSignOnService']['url'] ?? null;

        if (! is_string($ssoUrl) || $ssoUrl === '') {
            throw new MetadataUnreadable('That provider publishes no SingleSignOnService endpoint, so there is nowhere to send people to sign in.');
        }

        $certificates = $this->certificates($idp);

        if ($certificates === []) {
            throw new MetadataUnreadable('That provider publishes no signing certificate, so its assertions could not be verified.');
        }

        $warnings = [];
        $document = $this->document($xml);

        $entityCount = Utils::query($document, '//md:EntityDescriptor')->length;

        if ($entityCount > 1) {
            $warnings[] = 'The file describes '.$entityCount.' providers. The first one, "'.$idp['entityId'].'", was used and the rest ignored.';
        }

        $binding = (string) ($idp['singleSignOnService']['binding'] ?? '');

        if ($binding !== Constants::BINDING_HTTP_REDIRECT) {
            $warnings[] = 'The sign-in endpoint is published for '
                .($binding === '' ? 'no stated binding' : 'the '.$binding.' binding')
                .' rather than HTTP-Redirect, which is the one this application uses. Sign-in may not work.';
        }

        $sloUrl = $idp['singleLogoutService']['url'] ?? null;

        if (! is_string($sloUrl) || $sloUrl === '') {
            $sloUrl = null;
            $warnings[] = 'The provider publishes no SingleLogoutService endpoint, so signing out here will not sign the person out of the identity provider.';
        }

        $validUntil = $this->validUntil($document);

        if ($validUntil !== null && $validUntil->isPast()) {
            $warnings[] = 'The document expired on '.$validUntil->format('j M Y').'. It was still read, but the provider is publishing stale metadata.';
        }

        foreach ($certificates as $certificate) {
            if ($certificate->hasExpired()) {
                $warnings[] = 'A signing certificate has already expired ('.$certificate->describe().').';
            }
        }

        return new IdpMetadata(
            entityId: (string) $idp['entityId'],
            ssoUrl: $ssoUrl,
            sloUrl: $sloUrl,
            signingCertificates: $certificates,
            nameIdFormat: $this->nameIdFormat($parsed),
            validUntil: $validUntil,
            warnings: $warnings,
        );
    }

    /**
     * The toolkit collapses a lone certificate into `x509cert` and only uses
     * `x509certMulti` when there is more than one, so both shapes are read.
     *
     * @param  array<string, mixed>  $idp
     * @return list<Certificate>
     */
    private function certificates(array $idp): array
    {
        $bodies = [];

        if (isset($idp['x509cert']) && is_string($idp['x509cert']) && $idp['x509cert'] !== '') {
            $bodies[] = $idp['x509cert'];
        }

        $signing = $idp['x509certMulti']['signing'] ?? [];

        if (is_array($signing)) {
            foreach ($signing as $body) {
                if (is_string($body) && $body !== '') {
                    $bodies[] = $body;
                }
            }
        }

        $bodies = array_values(array_unique(array_map(Certificate::normalise(...), $bodies)));

        return array_map(Certificate::fromBase64(...), $bodies);
    }

    /**
     * The package stores the short form — `emailAddress`, `persistent` — and
     * puts the URN prefix back when it builds the toolkit's settings.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function nameIdFormat(array $parsed): ?string
    {
        $format = $parsed['sp']['NameIDFormat'] ?? null;

        if (! is_string($format) || $format === '') {
            return null;
        }

        $suffix = strrchr($format, ':');

        return $suffix === false ? $format : substr($suffix, 1);
    }

    /**
     * `validUntil` may sit on the entity or on the descriptor; the earlier of
     * the two is when the document stops being current.
     */
    private function validUntil(DOMDocument $document): ?Carbon
    {
        $earliest = null;

        foreach (['//md:EntityDescriptor', '//md:IDPSSODescriptor'] as $path) {
            foreach (Utils::query($document, $path) as $node) {
                if (! $node instanceof DOMElement || ! $node->hasAttribute('validUntil')) {
                    continue;
                }

                try {
                    $date = Carbon::parse($node->getAttribute('validUntil'));
                } catch (Throwable) {
                    // A date this application cannot read is not a reason to
                    // refuse the document; it just cannot be checked.
                    continue;
                }

                if ($earliest === null || $date->lt($earliest)) {
                    $earliest = $date;
                }
            }
        }

        return $earliest;
    }

    /**
     * Loaded through the toolkit's own loader, which refuses a document with a
     * DOCTYPE — the XXE guard. Reached only after `parseXML` has already parsed
     * the same string, so a failure here cannot happen and is not tested for.
     */
    private function document(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;

        /** @var DOMDocument */
        return Utils::loadXML($document, $xml);
    }
}
