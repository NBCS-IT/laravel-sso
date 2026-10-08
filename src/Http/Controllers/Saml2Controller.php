<?php

namespace NBCSIT\Sso\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;
use NBCSIT\Sso\MultiCertificateOneLoginBuilder;
use NBCSIT\Sso\Saml2\Auth as BoundAuth;
use NBCSIT\Sso\Saml2\Saml2User;
use NBCSIT\Sso\SsoServiceProvider;
use OneLogin\Saml2\Error;
use OneLogin\Saml2\ValidationError;
use Slides\Saml2\Auth;
use Slides\Saml2\Http\Controllers\Saml2Controller as VendorSaml2Controller;
use Slides\Saml2\OneLoginBuilder;

/**
 * The vendor package's controller, with request binding and a checked logout
 * `returnTo`.
 *
 * The vendor's routes name its controller by class, and Laravel resolves a
 * route's controller through the container, so binding this over it in
 * {@see SsoServiceProvider} is enough for every one of those routes
 * to arrive here. Nothing about the routes themselves changes.
 *
 * Signatures match the vendor's exactly, which is why the assertion consumer
 * reads the session through the helper rather than taking a request.
 */
class Saml2Controller extends VendorSaml2Controller
{
    /**
     * Where the ID of the AuthnRequest waits for the response that answers it.
     */
    public const SESSION_REQUEST_ID = 'saml2.request_id';

    /**
     * Initiate a login request, remembering what was asked for.
     *
     * The toolkit is asked to hand back the URL rather than redirect to it
     * itself: with `$stay` false it calls `exit()`, and the ID it assigned to
     * the AuthnRequest a line earlier is never readable. That ID is the only
     * thing that can later tie a response to a request this application
     * actually sent.
     *
     * @param  Request  $request  Restated: the vendor's `@param` names the class
     *                            relative to its own namespace, and would
     *                            otherwise be inherited.
     *
     * @throws Error
     */
    public function login(Request $request, Auth $auth): RedirectResponse
    {
        $redirectUrl = $auth->getTenant()?->relay_state_url ?: config('saml2.loginRoute');

        $url = (string) $auth->login($request->query('returnTo', $redirectUrl), [], false, false, true);

        // Only when something is going to read it. Writing the ID regardless
        // would leave a session key nothing ever clears on every application
        // that has not turned the binding on.
        if ($this->bindsRequests()) {
            $request->session()->put(self::SESSION_REQUEST_ID, $auth->getBase()->getLastRequestID());
        }

        return redirect($url);
    }

    /**
     * Process the response, holding it to the request this session sent.
     *
     * `pull()` rather than `get()`: a request ID is good for exactly one
     * response, and leaving it in the session would let the next one reuse it.
     *
     * @throws Error
     * @throws ValidationError
     */
    public function acs(Auth $auth)
    {
        if ($this->bindsRequests()) {
            $requestId = session()->pull(self::SESSION_REQUEST_ID);

            $this->bound($auth)->expectResponseTo(is_string($requestId) ? $requestId : null);
        }

        return parent::acs($auth);
    }

    /**
     * Initiate a logout request, with `returnTo` held to this application.
     *
     * `returnTo` becomes the RelayState on the LogoutRequest and comes back as
     * the redirect after logout, so it gets the same treatment as the one on
     * the way in — see {@see Saml2User::getIntendedUrl()}.
     *
     * @param  Request  $request  Restated, as on {@see self::login()}.
     *
     * @throws Error
     */
    public function logout(Request $request, Auth $auth)
    {
        $returnTo = $request->query('returnTo');

        if (! is_string($returnTo) || ! Saml2User::isSameOrigin($returnTo, url('/'))) {
            $returnTo = null;
        }

        $auth->logout(
            $returnTo,
            $request->query('nameId'),
            $request->query('sessionIndex'),
        );
    }

    private function bindsRequests(): bool
    {
        return config()->boolean('saml.security.strict_request_binding', false);
    }

    /**
     * The handler, as the class that can be told which request to expect.
     *
     * It always is in a correctly wired application —
     * {@see MultiCertificateOneLoginBuilder} constructs it. Anything
     * else means the builder has been rebound, and carrying on would sign
     * people in with the binding silently not applied.
     */
    private function bound(Auth $auth): BoundAuth
    {
        if (! $auth instanceof BoundAuth) {
            throw new LogicException(
                'Request binding is on, but the SAML handler is '.$auth::class.' rather than '.BoundAuth::class
                .'. Something has rebound '.OneLoginBuilder::class.' over this package\'s builder.',
            );
        }

        return $auth;
    }
}
