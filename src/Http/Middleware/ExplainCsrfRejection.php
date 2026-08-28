<?php

namespace NBCSIT\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use NBCSIT\Sso\Exceptions\CsrfAtTheAssertionConsumer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns a CSRF rejection on the vendor package's routes into a message that
 * says what is actually wrong.
 *
 * Applied only where an application has set `saml2.routesMiddleware` itself —
 * this package's own `saml.session` group cannot verify a token, so there is
 * nothing to explain there. A group of the application's own can, and `['web']`
 * is what an earlier generation of this implementation left behind: the
 * assertion consumer then runs behind `PreventRequestForgery` and every sign-in
 * ends on a 419 page that blames an expired page.
 *
 * Written as a rejection this catches rather than a middleware list it reads,
 * on purpose. Reading the list means naming a class that has been renamed twice
 * — `VerifyCsrfToken`, `ValidateCsrfToken`, now `PreventRequestForgery`, with
 * the earlier two surviving as subclasses — and it reports a false positive
 * against an application that has legitimately excluded `saml2/*` from token
 * verification. Catching the outcome fires only when a request was actually
 * refused.
 *
 * It cannot see a CSRF check applied globally in `bootstrap/app.php`, which
 * runs outside route middleware entirely. Laravel's skeleton puts the check in
 * the `web` group rather than there.
 */
class ExplainCsrfRejection
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $rejection) {
            if (! $this->isCsrfRejection($rejection)) {
                throw $rejection;
            }

            throw CsrfAtTheAssertionConsumer::rejectedTheIdentityProvidersPost($request->path(), $rejection);
        }
    }

    /**
     * The status is checked as well as the two exception types, because an
     * application that has written its own token check reports it as a 419 and
     * nothing else does.
     */
    private function isCsrfRejection(Throwable $rejection): bool
    {
        return $rejection instanceof TokenMismatchException
            || $rejection instanceof OriginMismatchException
            || ($rejection instanceof HttpExceptionInterface && $rejection->getStatusCode() === 419);
    }
}
