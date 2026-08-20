<?php

namespace NBCSIT\Sso\Exceptions;

use RuntimeException;

/**
 * The assertion consumer ran without a session.
 *
 * Thrown rather than swallowed because the alternative is worse: the sign-in
 * succeeds, writes the authenticated user into a session that is discarded with
 * the response, and the only symptom is a redirect loop several requests later
 * with nothing in the logs to explain it.
 */
class MissingSamlSession extends RuntimeException
{
    public static function atTheAssertionConsumer(): self
    {
        return new self(
            'The SAML assertion consumer ran without a started session, so signing a user in here would be discarded '
            .'with the response. The vendor package\'s routes need session middleware: leave `saml2.routesMiddleware` '
            .'unset or empty and this package applies its own `saml.session` group, or include equivalent middleware '
            .'in whatever you have set it to.',
        );
    }
}
