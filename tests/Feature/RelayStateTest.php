<?php

use Illuminate\Http\Request;
use NBCSIT\Sso\Http\Controllers\Saml2Controller;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Saml2\Auth;
use NBCSIT\Sso\Saml2\Saml2User;
use OneLogin\Saml2\Auth as OneLoginAuth;

/*
|--------------------------------------------------------------------------
| Redirects taken from a request stay on this application
|--------------------------------------------------------------------------
|
| RelayState round-trips through the identity provider unmodified and arrives
| at the assertion consumer as a plain request parameter, so whatever is in it
| is attacker-controlled. Redirecting to it means this application issuing a
| redirect, from its own origin, in the same breath as a successful sign-in.
| The logout route's `returnTo` becomes the RelayState of the LogoutRequest and
| comes back the same way.
|
*/

const APP_ROOT = 'https://app.example.edu.au';

/**
 * What the vendor controller would redirect to after sign-in, given this
 * RelayState.
 */
function intendedUrl(mixed $relayState): ?string
{
    config(['app.url' => APP_ROOT]);

    $request = Request::create(APP_ROOT.'/saml2/uuid/acs', 'POST', ['RelayState' => $relayState]);
    app()->instance('request', $request);
    url()->setRequest($request);

    return (new Auth(Mockery::mock(OneLoginAuth::class), new IdentityProvider))
        ->getSaml2User()
        ->getIntendedUrl();
}

it('hands every listener and the vendor controller the checked user', function () {
    expect((new Auth(Mockery::mock(OneLoginAuth::class), new IdentityProvider))->getSaml2User())
        ->toBeInstanceOf(Saml2User::class);
});

it('allows somewhere on this application', function (string $relayState) {
    expect(intendedUrl($relayState))->toBe($relayState);
})->with([
    'a path' => '/dashboard',
    'an absolute URL on this host' => APP_ROOT.'/dashboard',
    'this host in another case' => 'https://APP.EXAMPLE.EDU.AU/dashboard',
]);

it('refuses anywhere else', function (mixed $relayState) {
    expect(intendedUrl($relayState))->toBeNull();
})->with([
    'another host' => 'https://evil.example/harvest',
    // Ends with the right string, and is not the right host.
    'a host that merely starts with this one' => 'https://app.example.edu.au.evil.example/',
    'a protocol-relative URL' => '//evil.example/harvest',
    'a backslash after a slash' => '/\\evil.example/harvest',
    'a slash after a backslash' => '\\/evil.example/harvest',
    'two backslashes' => '\\\\evil.example/harvest',
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html,<script>alert(1)</script>',
    'a URL PHP cannot parse' => 'https:///evil.example',
]);

it('refuses nothing in particular', function (mixed $relayState) {
    expect(intendedUrl($relayState))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'an array' => [['an', 'array']],
    // What a front end sends when the variable it meant to send was unset.
    'undefined' => 'undefined',
]);

/*
| The vendor package's original guard, kept: bouncing the assertion consumer at
| itself is a loop.
*/
it('refuses the assertion consumer itself', function () {
    expect(intendedUrl(APP_ROOT.'/saml2/uuid/acs'))->toBeNull();
});

describe('the logout returnTo', function () {
    /**
     * What the controller hands the toolkit as the logout's RelayState.
     */
    function logoutReturnTo(mixed $returnTo): mixed
    {
        config(['app.url' => APP_ROOT]);

        $handler = Mockery::mock(Auth::class);
        $handler->shouldReceive('logout')->once()->withArgs(function (...$arguments) use (&$given) {
            $given = $arguments[0];

            return true;
        });

        app(Saml2Controller::class)->logout(
            Request::create(APP_ROOT.'/saml2/uuid/logout', 'GET', ['returnTo' => $returnTo]),
            $handler,
        );

        return $given;
    }

    it('passes somewhere on this application through', function () {
        expect(logoutReturnTo('/goodbye'))->toBe('/goodbye');
    });

    it('drops anywhere else', function () {
        expect(logoutReturnTo('https://evil.example/harvest'))->toBeNull()
            ->and(logoutReturnTo('//evil.example/harvest'))->toBeNull()
            ->and(logoutReturnTo(['an', 'array']))->toBeNull();
    });

    it('still passes the NameID and session index along', function () {
        $handler = Mockery::mock(Auth::class);
        $handler->shouldReceive('logout')->once()->with(null, 'name-id-1', 'session-1');

        app(Saml2Controller::class)->logout(
            Request::create('/saml2/uuid/logout', 'GET', ['nameId' => 'name-id-1', 'sessionIndex' => 'session-1']),
            $handler,
        );
    });
});
