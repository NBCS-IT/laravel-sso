<?php

namespace NBCSIT\Sso\Saml2;

use NBCSIT\Sso\MultiCertificateOneLoginBuilder;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\ValidationError;
use Slides\Saml2\Auth as VendorAuth;

/**
 * The vendor package's authentication handler, with the two checks it does not
 * make.
 *
 * These used to be carried by a fork of the vendor package. They live here now
 * so that the package itself can be used unmodified; every other method is the
 * vendor's own.
 *
 * Constructed by {@see MultiCertificateOneLoginBuilder} and bound
 * under the vendor class's name, so its controller, its facade and anything
 * type-hinting {@see VendorAuth} all get this.
 */
class Auth extends VendorAuth
{
    /**
     * The ID of the AuthnRequest the next response must answer, when one is
     * expected at all.
     */
    private ?string $expectedRequestId = null;

    /**
     * Why this class, rather than the toolkit, refused the last message.
     */
    private ?string $refusal = null;

    /**
     * Require the response processed by {@see self::acs()} to answer this
     * request.
     *
     * The toolkit compares the response's InResponseTo against it, which is the
     * only thing that ties a response to a request this application actually
     * sent. Without it the assertion consumer accepts any validly signed,
     * in-date, correctly addressed response, whether or not anybody here asked
     * for it — which is login CSRF.
     */
    public function expectResponseTo(?string $requestId): void
    {
        $this->expectedRequestId = $requestId;
    }

    /**
     * The vendor's, with the expected request ID handed to the toolkit — which
     * the vendor's offers no way to do.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws Error
     * @throws ValidationError
     */
    public function acs(): ?array
    {
        $this->refusal = null;

        $this->base->processResponse($this->expectedRequestId);

        $errors = $this->base->getErrors();

        if (! empty($errors)) {
            return $errors;
        }

        if (! $this->base->isAuthenticated()) {
            return ['error' => 'Could not authenticate'];
        }

        return null;
    }

    /**
     * The vendor's, behind a check that the logout message is signed at all.
     *
     * php-saml only requires a LogoutRequest to be signed when
     * `wantMessagesSigned` is on, and that setting also governs responses,
     * where turning it on breaks any identity provider that signs the assertion
     * only. Everything else the toolkit checks here — Destination, Issuer,
     * NotOnOrAfter — is satisfiable from public values, so without this a GET
     * from an <img> tag ends the session of whichever browser loads it.
     *
     * The refusal comes before the toolkit is asked to do anything, so an
     * unsigned message never reaches the callback that fires `SignedOut`.
     *
     * @param  bool  $retrieveParametersFromServer
     * @return array<array-key, mixed>
     *
     * @throws Error
     */
    public function sls($retrieveParametersFromServer = false): array
    {
        $this->refusal = null;

        if (config()->boolean('saml.security.want_logout_signed', true) && ! $this->hasLogoutSignature()) {
            return $this->refuse('Unsigned logout message rejected');
        }

        return (array) parent::sls($retrieveParametersFromServer);
    }

    /**
     * This class's own reason when it was this class that refused, so that the
     * vendor controller logs and flashes something rather than nothing.
     */
    public function getLastErrorReason(): ?string
    {
        return $this->refusal ?? parent::getLastErrorReason();
    }

    public function getSaml2User(): Saml2User
    {
        return new Saml2User($this->base, $this->tenant);
    }

    /**
     * Whether the logout message carries a signature at all.
     *
     * The HTTP-Redirect binding puts it in the query string, which is where the
     * toolkit reads it from too. Whether it is a *valid* signature is the
     * toolkit's question, and it asks it.
     */
    private function hasLogoutSignature(): bool
    {
        $signature = request()->query('Signature');

        return is_string($signature) && $signature !== '';
    }

    /**
     * @return array{error: string}
     */
    private function refuse(string $reason): array
    {
        $this->refusal = $reason;

        return ['error' => $reason];
    }
}
