<?php

namespace NBCSIT\Sso\Saml2;

use Slides\Saml2\Saml2User as VendorSaml2User;

/**
 * The vendor package's user, with the RelayState it redirects to held to this
 * application's own origin.
 *
 * Returned by {@see Auth::getSaml2User()}, so it is what the vendor controller
 * reads the post-sign-in redirect from, and what every `SignedIn` listener
 * receives.
 */
class Saml2User extends VendorSaml2User
{
    /**
     * The vendor's intended URL, when it points at this application.
     *
     * RelayState round-trips through the identity provider unmodified and comes
     * back as a plain POST parameter at the assertion consumer, so it is
     * attacker-controlled. A redirect issued from it carries this application's
     * own credibility, immediately after a successful sign-in, which is the most
     * credible possible moment to hand somebody off somewhere else.
     *
     * `undefined` is refused too: it is what a front end sends as `returnTo`
     * when the variable it meant to send was never set, and redirecting to it
     * is a 404 on a page nobody asked for.
     */
    public function getIntendedUrl(): ?string
    {
        $intended = parent::getIntendedUrl();

        if (! is_string($intended) || $intended === '' || $intended === 'undefined') {
            return null;
        }

        return static::isSameOrigin($intended, url('/')) ? $intended : null;
    }

    /**
     * Whether a URL taken from a request points at this application.
     *
     * Three things are refused. An absolute URL on another host, which is the
     * obvious case. A scheme that is not http(s), so that "javascript:" and
     * friends cannot reach a Location header. And the protocol-relative forms —
     * "//elsewhere", and the backslash variants some browsers normalise into
     * them — which look like paths and are not.
     *
     * @param  string  $base  The application's own root URL.
     */
    public static function isSameOrigin(string $candidate, string $base): bool
    {
        if (preg_match('#^\s*[/\\\\]{2}#', $candidate)) {
            return false;
        }

        $parts = parse_url($candidate);

        if ($parts === false) {
            return false;
        }

        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        if (! isset($parts['host'])) {
            // A path, which can only be resolved against this application.
            return true;
        }

        $expected = parse_url($base, PHP_URL_HOST);

        return is_string($expected) && strcasecmp($parts['host'], $expected) === 0;
    }
}
