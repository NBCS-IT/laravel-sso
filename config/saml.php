<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The user model, and the columns single sign-on writes
    |--------------------------------------------------------------------------
    |
    | `name_id` is the only column this package cannot work without: it is the
    | stable identity an assertion carries, and matching on email alone orphans
    | anybody who changes their surname.
    |
    | `active` and `last_login_at` are null by default and that is deliberate —
    | most applications have neither column. Naming a column switches the
    | behaviour on: with `active` set, an account whose flag is false is refused
    | at sign-in; with it null, every account that exists may sign in.
    |
    | `link_domains` limits which email addresses may claim an existing local
    | account. An assertion is matched on NameID first and, failing that, on
    | email — which is how an account somebody created by hand gets connected to
    | single sign-on the first time its owner signs in. That fallback treats the
    | address in the assertion as proof of ownership of whatever account already
    | holds it, so where the identity provider admits guests, or lets a user
    | edit their own mail attribute, it is a way to capture an account.
    |
    | Empty means any domain, which is right for an application that genuinely
    | signs external people in. Where every account belongs to one organisation,
    | naming its domains here closes the case entirely. Matching on NameID is
    | never restricted: that is the identity the provider guarantees.
    |
    | Every first link is recorded either way — see NBCSIT\Sso\Models\SamlAccountLink.
    |
    */

    'user' => [
        'model' => env('SAML_USER_MODEL', 'App\Models\User'),

        // e.g. ['nbcs.nsw.edu.au']; empty allows any domain.
        'link_domains' => [],

        'columns' => [
            'name_id' => 'saml_name_id',
            'email' => 'email',
            'name' => 'name',

            // e.g. 'is_active'; null skips the active check entirely.
            'active' => null,

            // e.g. 'last_login_at'; null skips the write.
            'last_login_at' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The guard roles are granted under
    |--------------------------------------------------------------------------
    |
    | Spatie Permission refuses to assign a role that exists under a different
    | guard, which a single-guard application never notices and a multi-guard
    | one hits on the first login.
    |
    */

    'guard' => env('SAML_GUARD', 'web'),

    /*
    |--------------------------------------------------------------------------
    | Where a visitor goes when single sign-on is not configured
    |--------------------------------------------------------------------------
    |
    | A named route, so a fresh install is still administrable through the local
    | password form before the identity provider's metadata has been loaded. If
    | the route does not exist the middleware aborts rather than looping.
    |
    */

    'fallback_login_route' => env('SAML_FALLBACK_LOGIN_ROUTE', 'login'),

    /*
    |--------------------------------------------------------------------------
    | The ability the published admin screens authorise against
    |--------------------------------------------------------------------------
    */

    'gate' => env('SAML_GATE', 'manage settings'),

    /*
    |--------------------------------------------------------------------------
    | Protocol security floor
    |--------------------------------------------------------------------------
    |
    | `want_assertions_signed` is not here: it is not negotiable and is forced on
    | in MultiCertificateOneLoginBuilder, along with `strict` and XML schema
    | validation. What is here is the handful of demands on the identity provider
    | that cannot be made unilaterally without breaking an integration that is
    | otherwise sound.
    |
    | `want_messages_signed` additionally requires the <samlp:Response> envelope
    | to be signed. Entra ID does not do that unless the enterprise application
    | is set to "Sign SAML response and assertion" — switch that on at the
    | identity provider first, then switch this on here.
    |
    | `strict_request_binding` ties each response to the AuthnRequest this
    | application sent, which is what closes login CSRF — without it the
    | assertion consumer accepts any validly signed, in-date, correctly
    | addressed response, whether or not anybody here asked for it. It is
    | carried down to the vendor package as `saml2.strictRequestBinding`, and
    | needs nbcsit/laravel-saml2 2.5.0 or later, which is the release that keeps
    | the request ID.
    |
    | On by default. **It refuses IdP-initiated sign-in**, so an application
    | reached through the Entra "My Apps" tile must either retire the tile or
    | switch this off.
    |
    | It also requires `SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true`.
    | The identity provider POSTs its response to the assertion consumer from
    | its own origin, and Laravel's default `Lax` cookie is withheld on a
    | cross-site POST — so the request ID stored at login is not there to match
    | against, and every sign-in is refused. See the README.
    |
    | `reject_unsolicited` additionally has the toolkit refuse a response
    | carrying an InResponseTo it cannot account for, and only takes effect
    | together with the switch above — on its own it refuses every ordinary
    | sign-in, because Entra answers an AuthnRequest with an InResponseTo and
    | there would be no stored request ID to match it against.
    |
    | Together they decide what a lost request ID means. That happens when a
    | session did not survive the round trip to the identity provider: a dropped
    | cookie, an expired session, a browser that discarded it. With this on, the
    | sign-in fails and the person tries again. With it off, the binding
    | silently falls back to accepting any valid response, which is the thing
    | the binding exists to prevent — so it is on.
    |
    | `allow_unkeyed_assertions` decides what happens when a response arrives
    | with neither an assertion ID nor a message ID, which leaves replay
    | detection nothing to key on. Off means such a response is refused. Turning
    | it on trades replay protection for compatibility with an identity provider
    | that emits malformed responses, and every such sign-in is logged.
    |
    */

    'security' => [
        'want_messages_signed' => env('SAML_WANT_MESSAGES_SIGNED', false),
        'reject_unsolicited' => env('SAML_REJECT_UNSOLICITED', true),
        'strict_request_binding' => env('SAML_STRICT_REQUEST_BINDING', true),
        'allow_unkeyed_assertions' => env('SAML_ALLOW_UNKEYED_ASSERTIONS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | This application's own signing certificate
    |--------------------------------------------------------------------------
    |
    | Where the service provider's certificate and private key are kept, and
    | what a generated one says about itself. Both files are read on the request
    | that needs them, never at config load, so `config:cache` cannot freeze a
    | certificate that has since been rolled.
    |
    | **The disk must not be web-served.** A private key on a disk with a `url`
    | is a private key anybody can download, and the package refuses to write to
    | one for that reason. Add the path to your `.gitignore` too.
    |
    | `local` means `storage/app/private`, which is per-node: generate on one
    | web server and the others sign with something else, which fails for about
    | half of all sign-ins and looks nothing like a certificate problem. On more
    | than one node, point this at shared storage or bake the files into the
    | image.
    |
    | The subject is what tells two certificates apart in an identity provider's
    | console. Anything left null is worked out from the application — the
    | common name from `APP_URL`'s host, the organisation from `APP_NAME` — and
    | anything still empty is left out of the certificate rather than sent as a
    | blank.
    |
    */

    'certificate' => [
        'disk' => env('SAML_CERT_DISK', 'local'),
        'path' => env('SAML_CERT_PATH', 'certs'),
        'days' => env('SAML_CERT_DAYS', 3650),
        'bits' => env('SAML_CERT_BITS', 2048),

        'subject' => [
            'commonName' => env('SAML_CERT_COMMON_NAME'),
            'organizationName' => env('SAML_CERT_ORGANISATION'),
            'organizationalUnitName' => env('SAML_CERT_ORGANISATIONAL_UNIT'),
            'countryName' => env('SAML_CERT_COUNTRY'),
            'stateOrProvinceName' => env('SAML_CERT_STATE'),
            'localityName' => env('SAML_CERT_LOCALITY'),
            'emailAddress' => env('SAML_CERT_EMAIL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The scheduled metadata refresh
    |--------------------------------------------------------------------------
    |
    | `saml:refresh-metadata` re-reads every provider that has a metadata URL
    | and automatic refresh switched on. A package that silently schedules a job
    | is unwelcome, so the job is here where it can be seen and switched off.
    |
    | `allow_private_hosts` lifts the check that a metadata URL resolves to a
    | public address. The check is there because the URL is administrator-typed
    | and fetched unattended, which otherwise makes the daily run a request
    | generator pointed anywhere the web node can reach. Lift it only for an
    | identity provider that genuinely lives on the internal network — an ADFS
    | server, typically — and understand that doing so lifts it for every
    | provider.
    |
    */

    'refresh' => [
        'enabled' => env('SAML_REFRESH_ENABLED', true),
        'schedule' => env('SAML_REFRESH_SCHEDULE', '03:15'),
        'allow_private_hosts' => env('SAML_REFRESH_ALLOW_PRIVATE_HOSTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trimming the replay table
    |--------------------------------------------------------------------------
    |
    | `saml_assertions` gains a row per sign-in, and an assertion that can no
    | longer be valid proves nothing by being remembered. This schedules
    | `model:prune` against that one model — never the application's own — so
    | the table does not grow without bound.
    |
    | Its own switch rather than the refresh's: switching the metadata refresh
    | off should not quietly stop this too.
    |
    */

    'prune' => [
        'enabled' => env('SAML_PRUNE_ENABLED', true),
        'schedule' => env('SAML_PRUNE_SCHEDULE', '03:45'),
    ],

];
