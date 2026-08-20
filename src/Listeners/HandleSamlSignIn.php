<?php

namespace NBCSIT\Sso\Listeners;

use Illuminate\Support\Carbon;
use NBCSIT\Saml2\Events\SignedIn;
use NBCSIT\Saml2\Models\Tenant;
use NBCSIT\Sso\Exceptions\MissingSamlSession;
use NBCSIT\Sso\SamlAuthenticator;
use NBCSIT\Sso\Support\SamlIdentity;
use Throwable;

/**
 * Adapts the SAML package's SignedIn event onto {@see SamlAuthenticator}.
 *
 * The outcome is left in the session for RequireSamlAuthentication to turn into
 * a page: the package controls the redirect after this event, so there is no
 * response to return from here.
 */
class HandleSamlSignIn
{
    public const SESSION_OUTCOME = 'saml.outcome';

    public const SESSION_NAME_ID = 'saml.name_id';

    public function __construct(
        private readonly SamlAuthenticator $authenticator,
    ) {}

    public function handle(SignedIn $event): void
    {
        $this->requireSession();

        $samlUser = $event->getSaml2User();
        $auth = $event->getAuth();

        $identity = new SamlIdentity(
            nameId: (string) $samlUser->getNameId(),
            attributes: $samlUser->getAttributes(),
            messageId: $this->messageId($auth),
            notOnOrAfter: $this->notOnOrAfter($auth),
            assertionId: $this->assertionId($auth),
        );

        session([self::SESSION_NAME_ID => $identity->nameId]);

        $outcome = $this->authenticator->authenticate($identity, $this->tenant($auth));

        if ($outcome->succeeded()) {
            session()->forget([self::SESSION_OUTCOME, self::SESSION_NAME_ID]);

            return;
        }

        session([self::SESSION_OUTCOME => $outcome->value]);
    }

    /**
     * Refuse to authenticate into a session that will not be written back.
     *
     * `SsoServiceProvider` gives the vendor package's routes session middleware
     * by default, so reaching this is a sign the application has set
     * `saml2.routesMiddleware` to something of its own that lacks it — the one
     * case the default cannot cover.
     */
    private function requireSession(): void
    {
        if (! session()->isStarted()) {
            throw MissingSamlSession::atTheAssertionConsumer();
        }
    }

    /**
     * Some toolkit versions and IdP quirks leave no message ID; replay
     * detection then falls back to the toolkit's own timestamp validation.
     */
    private function messageId(mixed $auth): ?string
    {
        try {
            $id = $auth->getLastMessageId();
        } catch (Throwable) {
            return null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The provider whose assertion this is.
     *
     * The vendor package resolved it from the URL before the toolkit looked at
     * the response, so it is always there in the real flow; read defensively
     * anyway, in keeping with everything else this listener pulls off the
     * toolkit's objects.
     */
    private function tenant(mixed $auth): ?Tenant
    {
        try {
            $tenant = $auth->getTenant();
        } catch (Throwable) {
            return null;
        }

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * The ID of the assertion itself, which is what replay detection wants —
     * see {@see SamlIdentity::replayKey()}. Only the toolkit's own object
     * carries it, and it is read as defensively as everything else here: a
     * provider that reports nothing falls back to the message ID rather than
     * failing the sign-in from inside an accessor.
     */
    private function assertionId(mixed $auth): ?string
    {
        try {
            $id = $auth->getBase()->getLastAssertionId();
        } catch (Throwable) {
            return null;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function notOnOrAfter(mixed $auth): ?Carbon
    {
        try {
            $timestamp = $auth->getBase()->getLastAssertionNotOnOrAfter();
        } catch (Throwable) {
            return null;
        }

        return is_int($timestamp) || (is_string($timestamp) && is_numeric($timestamp))
            ? Carbon::createFromTimestamp((int) $timestamp)
            : null;
    }
}
