<?php

namespace NBCSIT\Sso\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use NBCSIT\Sso\Contracts\ResolvesSamlUsers;
use NBCSIT\Sso\Enums\SamlLoginOutcome;
use NBCSIT\Sso\Listeners\HandleSamlSignIn;
use NBCSIT\Sso\Settings\SamlSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends anonymous visitors to the identity provider, and explains itself when
 * an assertion came back but no session resulted.
 *
 * With SAML unconfigured it falls through to the application's own login route
 * — `config('saml.fallback_login_route')` — so a fresh install is still
 * administrable before the IdP metadata is loaded.
 */
class RequireSamlAuthentication
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $this->requireStillActive($request, $user);

            return $next($request);
        }

        // An assertion arrived but sign-in did not complete. Say why, rather
        // than bouncing back to the IdP for another identical round trip.
        $outcome = SamlLoginOutcome::tryFrom((string) $request->session()->get(HandleSamlSignIn::SESSION_OUTCOME));

        if ($outcome !== null && ! $outcome->succeeded()) {
            $request->session()->forget([HandleSamlSignIn::SESSION_OUTCOME, HandleSamlSignIn::SESSION_NAME_ID]);

            abort(403, $outcome->message());
        }

        $settings = app(SamlSettings::class);
        $tenant = $settings->enabled ? $settings->activeTenant() : null;

        if ($tenant === null) {
            return redirect()->guest(route($this->fallbackRoute()));
        }

        return redirect(saml_url($request->fullUrl(), $tenant->uuid));
    }

    /**
     * Deactivation has to bite before the next sign-in, or it does not bite.
     *
     * `isActive()` was only ever consulted while consuming an assertion, so
     * clearing the flag left whoever was already signed in working until their
     * session expired on its own — which is the opposite of what "deactivate
     * this account now" means. The user model is already loaded by the guard, so
     * checking here costs no query, and an application with no `active` column
     * configured gets true and is unaffected.
     */
    private function requireStillActive(Request $request, Authenticatable $user): void
    {
        if (app(ResolvesSamlUsers::class)->isActive($user)) {
            return;
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort(403, SamlLoginOutcome::Inactive->message());
    }

    /**
     * A misconfigured fallback would redirect a guest at a route that does not
     * exist, and the sensible-looking recovery — send them here again — is a
     * loop. Failing loudly, naming the config key, is the kinder outcome.
     */
    private function fallbackRoute(): string
    {
        $name = (string) config('saml.fallback_login_route');

        abort_unless(
            Route::has($name),
            500,
            'config(\'saml.fallback_login_route\') names the route "'.$name.'", which this application does not have.',
        );

        return $name;
    }
}
