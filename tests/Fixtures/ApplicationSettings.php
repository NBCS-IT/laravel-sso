<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use Spatie\LaravelSettings\Settings;

/**
 * A settings class belonging to the "application" the harness stands up.
 *
 * It exists to prove a negative: registering this package must append to
 * Spatie's three config arrays, never replace them. Silently unregistering a
 * consumer's own settings classes would break things that look nothing like a
 * SAML bug.
 */
class ApplicationSettings extends Settings
{
    public string $unrelated_to_saml;

    public static function group(): string
    {
        return 'application';
    }
}
