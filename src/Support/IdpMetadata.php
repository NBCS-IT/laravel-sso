<?php

namespace NBCSIT\Sso\Support;

use Illuminate\Support\Carbon;

/**
 * The parts of a SAML metadata document this application actually uses.
 *
 * A metadata file carries a great deal more than this — attribute profiles,
 * organisation blocks, endpoints for bindings we do not speak. Narrowing it to
 * these fields up front is what makes a change detectable: two documents that
 * differ only in their `ID` attribute produce the same value here, and so do not
 * wake anyone at 03:15.
 */
final readonly class IdpMetadata
{
    /**
     * @param  list<Certificate>  $signingCertificates
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $entityId,
        public string $ssoUrl,
        public ?string $sloUrl,
        public array $signingCertificates,
        public ?string $nameIdFormat,
        public ?Carbon $validUntil,
        public array $warnings = [],
    ) {}

    /**
     * The certificate that goes in the tenant's single-certificate column.
     */
    public function primaryCertificate(): Certificate
    {
        return $this->signingCertificates[0];
    }

    /**
     * @return list<string>
     */
    public function certificateBodies(): array
    {
        return array_map(fn (Certificate $certificate) => $certificate->body, $this->signingCertificates);
    }

    /**
     * A digest of exactly the values above, so "has anything moved?" is one
     * string comparison rather than a field-by-field diff on every scheduled
     * run. Certificates are sorted: an IdP that reorders its KeyDescriptors has
     * not changed its keys.
     */
    public function fingerprint(): string
    {
        $certificates = $this->certificateBodies();
        sort($certificates);

        return hash('sha256', implode("\n", [
            $this->entityId,
            $this->ssoUrl,
            $this->sloUrl ?? '',
            $this->nameIdFormat ?? '',
            ...$certificates,
        ]));
    }
}
