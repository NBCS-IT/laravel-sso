<?php

namespace NBCSIT\Sso\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A SAML route verified a CSRF token, and the identity provider had none.
 *
 * Thrown rather than left as the 419 it arrived as, because the 419 page says
 * the page expired — which is both untrue and unactionable. The identity
 * provider posts the assertion cross-site from its own origin: there is no
 * token to send, there never was one, and no amount of retrying produces one.
 * The application has set `saml2.routesMiddleware` to a group that verifies
 * tokens, and every sign-in ends here having authenticated nobody.
 */
class CsrfAtTheAssertionConsumer extends RuntimeException
{
    public static function rejectedTheIdentityProvidersPost(string $path, Throwable $previous): self
    {
        return new self(
            'The SAML route "'.$path.'" refused a cross-site POST for want of a CSRF token, which the identity '
            .'provider has no way to send — the signed assertion is what this endpoint verifies instead. '
            .'`saml2.routesMiddleware` is set to something that verifies tokens, and `[\'web\']` is the usual '
            .'reason: leave it unset or empty and this package applies its own `saml.session` group, or exclude '
            .'the SAML routes from CSRF verification in whatever you have set it to.',
            0,
            $previous,
        );
    }
}
