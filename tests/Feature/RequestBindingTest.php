<?php

use Illuminate\Http\Request;
use NBCSIT\Sso\Http\Controllers\Saml2Controller;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Saml2\Auth;
use OneLogin\Saml2\Auth as OneLoginAuth;
use Slides\Saml2\Auth as VendorAuth;

/*
|--------------------------------------------------------------------------
| Request binding, end to end
|--------------------------------------------------------------------------
|
| Without it the assertion consumer accepts any validly signed, in-date,
| correctly addressed response, whether or not this application asked for it —
| which is login CSRF: a response minted for one subject delivered into another
| browser, which then holds a session as that subject.
|
| These run through the vendor package's own routes and the real toolkit, which
| is the only proof that the controller bound over the vendor's is the one that
| answers. A response is not signed here, and does not need to be: the toolkit
| compares InResponseTo before it looks at a signature, so the reason it gives
| for refusing says which request ID it was handed.
|
*/

/**
 * An unsigned, schema-valid response, base64-encoded as the HTTP-POST binding
 * carries it.
 */
function unsignedResponse(?string $inResponseTo): string
{
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $answering = $inResponseTo === null ? '' : ' InResponseTo="'.$inResponseTo.'"';

    return base64_encode(<<<XML
        <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"
            xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"
            ID="_response" Version="2.0" IssueInstant="{$now}"{$answering}>
            <saml:Issuer>https://idp.example.edu.au/saml</saml:Issuer>
            <samlp:Status>
                <samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/>
            </samlp:Status>
            <saml:Assertion ID="_assertion" Version="2.0" IssueInstant="{$now}">
                <saml:Issuer>https://idp.example.edu.au/saml</saml:Issuer>
            </saml:Assertion>
        </samlp:Response>
        XML);
}

/**
 * Post a response to the assertion consumer, where the toolkit will find it.
 *
 * php-saml reads `$_POST` itself rather than anything Laravel hands it, so the
 * superglobal is set as well as the request body.
 */
function postToAssertionConsumer(IdentityProvider $provider, string $response): void
{
    $_POST['SAMLResponse'] = $response;

    test()->post(route('saml.acs', ['uuid' => $provider->uuid]), ['SAMLResponse' => $response]);
}

/**
 * Why the toolkit refused the last response, as the vendor controller flashed
 * it.
 */
function refusalReason(): string
{
    return (string) (session('saml2.error_detail')[0] ?? '');
}

beforeEach(function () {
    $this->provider = IdentityProvider::factory()->create(['name_id_format' => 'persistent']);
});

afterEach(function () {
    unset($_POST['SAMLResponse']);
});

describe('at login', function () {
    it('keeps the ID of the request it sends', function () {
        config(['saml.security.strict_request_binding' => true]);

        $location = $this->get(route('saml.login', ['uuid' => $this->provider->uuid]))
            ->assertRedirect()
            ->headers->get('Location');

        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $sent = simplexml_load_string((string) gzinflate(base64_decode($query['SAMLRequest'])));

        expect($location)->toStartWith($this->provider->idp_login_url)
            ->and(session(Saml2Controller::SESSION_REQUEST_ID))->toBe((string) $sent['ID']);
    });

    /*
    | Writing it regardless would leave a session key nothing ever clears.
    */
    it('keeps nothing when the binding is off', function () {
        config(['saml.security.strict_request_binding' => false]);

        $this->get(route('saml.login', ['uuid' => $this->provider->uuid]))->assertRedirect();

        expect(session()->has(Saml2Controller::SESSION_REQUEST_ID))->toBeFalse();
    });

    it('still sends returnTo as the RelayState', function () {
        $location = $this->get(route('saml.login', [
            'uuid' => $this->provider->uuid,
            'returnTo' => 'https://localhost/dashboard',
        ]))->headers->get('Location');

        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

        expect($query['RelayState'])->toBe('https://localhost/dashboard');
    });
});

describe('at the assertion consumer', function () {
    it('hands the toolkit the ID it kept, so a response to some other request is refused', function () {
        config(['saml.security.strict_request_binding' => true]);

        $this->withSession([Saml2Controller::SESSION_REQUEST_ID => 'ONELOGIN_expected']);

        postToAssertionConsumer($this->provider, unsignedResponse('ONELOGIN_somebody_elses'));

        expect(refusalReason())->toContain('does not match the ID of the AuthNRequest sent by the SP: ONELOGIN_expected');
    });

    it('refuses a response nobody asked for while a request is pending', function () {
        config(['saml.security.strict_request_binding' => true]);

        $this->withSession([Saml2Controller::SESSION_REQUEST_ID => 'ONELOGIN_expected']);

        postToAssertionConsumer($this->provider, unsignedResponse(null));

        expect(refusalReason())->toContain('No InResponseTo at the Response');
    });

    /*
    | A request ID is good for exactly one response. Left in the session, the
    | next response could answer it too.
    */
    it('spends the ID on the first response that arrives', function () {
        config(['saml.security.strict_request_binding' => true]);

        $this->withSession([Saml2Controller::SESSION_REQUEST_ID => 'ONELOGIN_expected']);

        postToAssertionConsumer($this->provider, unsignedResponse('ONELOGIN_expected'));

        expect(session()->has(Saml2Controller::SESSION_REQUEST_ID))->toBeFalse();
    });

    it('hands the toolkit nothing when the binding is off', function () {
        config(['saml.security.strict_request_binding' => false]);

        $this->withSession([Saml2Controller::SESSION_REQUEST_ID => 'ONELOGIN_expected']);

        postToAssertionConsumer($this->provider, unsignedResponse('ONELOGIN_somebody_elses'));

        // Refused all the same — it is unsigned — but not over InResponseTo,
        // which is the toolkit saying it was given no request to compare.
        expect(refusalReason())->not->toBeEmpty()
            ->and(refusalReason())->not->toContain('InResponseTo');
    });

    /*
    | Only reachable if something has rebound the vendor builder over this
    | package's. Carrying on would sign people in with the binding silently not
    | applied.
    */
    it('refuses to carry on with a handler that cannot be told what to expect', function () {
        config(['saml.security.strict_request_binding' => true]);

        $handler = new VendorAuth(Mockery::mock(OneLoginAuth::class), $this->provider);

        expect(fn () => app(Saml2Controller::class)->acs($handler))
            ->toThrow(LogicException::class, 'Request binding is on');
    });
});

describe('the handler', function () {
    beforeEach(function () {
        $this->toolkit = Mockery::mock(OneLoginAuth::class);
        $this->handler = new Auth($this->toolkit, $this->provider);
    });

    it('forwards the request ID it was told to expect', function () {
        $this->toolkit->shouldReceive('processResponse')->with('ONELOGIN_abc123')->once();
        $this->toolkit->shouldReceive('getErrors')->once()->andReturn([]);
        $this->toolkit->shouldReceive('isAuthenticated')->once()->andReturn(true);

        $this->handler->expectResponseTo('ONELOGIN_abc123');

        expect($this->handler->acs())->toBeNull();
    });

    it('forwards nothing when told nothing', function () {
        $this->toolkit->shouldReceive('processResponse')->with(null)->once();
        $this->toolkit->shouldReceive('getErrors')->once()->andReturn([]);
        $this->toolkit->shouldReceive('isAuthenticated')->once()->andReturn(true);

        expect($this->handler->acs())->toBeNull();
    });

    it('returns the toolkit\'s errors, and its reason', function () {
        $this->toolkit->shouldReceive('processResponse')->once();
        $this->toolkit->shouldReceive('getErrors')->once()->andReturn(['invalid_response']);
        $this->toolkit->shouldReceive('getLastErrorReason')->once()->andReturn('Signature validation failed');

        expect($this->handler->acs())->toBe(['invalid_response'])
            ->and($this->handler->getLastErrorReason())->toBe('Signature validation failed');
    });

    it('refuses a response that validated but authenticated nobody', function () {
        $this->toolkit->shouldReceive('processResponse')->once();
        $this->toolkit->shouldReceive('getErrors')->once()->andReturn([]);
        $this->toolkit->shouldReceive('isAuthenticated')->once()->andReturn(false);

        expect($this->handler->acs())->toBe(['error' => 'Could not authenticate']);
    });
});

/*
| The ID has to be read off the toolkit before anything redirects, which is
| the whole reason the controller asks for the URL rather than letting the
| toolkit send the browser there and exit().
*/
it('asks the toolkit for the URL rather than letting it redirect', function () {
    config(['saml.security.strict_request_binding' => true]);

    $toolkit = Mockery::mock(OneLoginAuth::class);
    $toolkit->shouldReceive('login')
        ->with('/dashboard', [], false, false, true, true)
        ->once()
        ->andReturn('https://idp.example.edu.au/saml/sso?SAMLRequest=abc');
    $toolkit->shouldReceive('getLastRequestID')->once()->andReturn('ONELOGIN_abc123');

    $request = Request::create('/saml2/'.$this->provider->uuid.'/login', 'GET', ['returnTo' => '/dashboard']);
    $request->setLaravelSession(session()->driver());

    $response = app(Saml2Controller::class)->login($request, new Auth($toolkit, $this->provider));

    expect($response->getTargetUrl())->toBe('https://idp.example.edu.au/saml/sso?SAMLRequest=abc')
        ->and(session(Saml2Controller::SESSION_REQUEST_ID))->toBe('ONELOGIN_abc123');
});
