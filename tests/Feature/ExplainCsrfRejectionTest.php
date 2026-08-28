<?php

use Illuminate\Http\Exceptions\OriginMismatchException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use NBCSIT\Sso\Exceptions\CsrfAtTheAssertionConsumer;
use NBCSIT\Sso\Http\Middleware\ExplainCsrfRejection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| The middleware this package prepends to a `saml2.routesMiddleware` an
| application set itself. What it converts is a rejection, not a middleware
| list: the CSRF middleware has been renamed twice and an application may
| legitimately have excluded the SAML routes from it, so only a request that
| was actually refused says anything.
*/

/**
 * An assertion consumer standing in for the vendor package's, behind this
 * middleware and whatever the application's own group did to the request.
 */
function acsRouteThat(?Throwable $rejection): void
{
    Route::middleware(ExplainCsrfRejection::class)
        ->post('assertion-consumer/abc/acs', function () use ($rejection) {
            if ($rejection !== null) {
                throw $rejection;
            }

            return 'the assertion was consumed';
        });
}

it('lets an assertion the application accepted through untouched', function () {
    acsRouteThat(null);

    $this->post('assertion-consumer/abc/acs')->assertOk()->assertSee('the assertion was consumed');
});

it('explains a token mismatch instead of leaving it as a 419', function () {
    acsRouteThat($rejection = new TokenMismatchException);

    try {
        $this->withoutExceptionHandling()->post('assertion-consumer/abc/acs');

        $this->fail('The CSRF rejection was not converted.');
    } catch (CsrfAtTheAssertionConsumer $explained) {
        expect($explained->getMessage())
            ->toContain('assertion-consumer/abc/acs')
            ->toContain('routesMiddleware')
            ->and($explained->getPrevious())->toBe($rejection);
    }
});

/*
| Newer Laravel refuses a cross-site POST on the origin before it ever looks at
| a token, which is the same misconfiguration arriving under a different name.
*/
it('explains an origin mismatch too', function () {
    acsRouteThat(new OriginMismatchException);

    $this->withoutExceptionHandling()->post('assertion-consumer/abc/acs');
})->throws(CsrfAtTheAssertionConsumer::class);

/*
| An application that wrote its own token check reports it as a 419 and nothing
| else does, so the status is enough to recognise it by.
*/
it('explains anything else that comes back as a 419', function () {
    acsRouteThat(new HttpException(419, 'Page Expired'));

    $this->withoutExceptionHandling()->post('assertion-consumer/abc/acs');
})->throws(CsrfAtTheAssertionConsumer::class);

it('leaves every other failure exactly as it was', function () {
    acsRouteThat(new RuntimeException('the toolkit could not read the response'));

    try {
        $this->withoutExceptionHandling()->post('assertion-consumer/abc/acs');

        $this->fail('The failure was swallowed.');
    } catch (RuntimeException $rethrown) {
        expect($rethrown)->not->toBeInstanceOf(CsrfAtTheAssertionConsumer::class)
            ->and($rethrown->getMessage())->toBe('the toolkit could not read the response');
    }
});
