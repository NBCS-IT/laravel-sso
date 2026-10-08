<?php

use Illuminate\Support\Facades\Event;
use NBCSIT\Sso\Models\IdentityProvider;
use OneLogin\Saml2\Error as OneLoginError;
use Slides\Saml2\Events\SignedOut;

/*
|--------------------------------------------------------------------------
| Unsigned logout messages, refused
|--------------------------------------------------------------------------
|
| The single logout endpoint is a GET, and the toolkit only requires a
| signature on a LogoutRequest when `wantMessagesSigned` is on — which cannot be
| turned on without also demanding a signed <samlp:Response> envelope, which
| Entra ID does not send by default. Everything else the toolkit checks there
| is satisfiable from public values, so without this an <img> tag ends the
| session of whichever browser loads it.
|
| Where a message is let through, the toolkit is what answers — and with no
| real message in `$_GET`, it answers by throwing. That throw is the proof the
| message got past this package's check.
|
*/

function sls(IdentityProvider $provider, array $query = [], string $method = 'get'): void
{
    test()->{$method}(route('saml.sls', ['uuid' => $provider->uuid, ...$query]));
}

beforeEach(function () {
    $this->provider = IdentityProvider::factory()->create(['name_id_format' => 'persistent']);

    Event::fake([SignedOut::class]);
});

it('refuses a logout message with no signature, before the toolkit sees it', function () {
    sls($this->provider, ['SAMLRequest' => 'anything']);

    expect(session('saml2.error'))->toBe(['error' => 'Unsigned logout message rejected'])
        ->and(session('saml2.error_detail'))->toBe(['Unsigned logout message rejected']);

    Event::assertNotDispatched(SignedOut::class);
});

it('refuses a blank signature', function () {
    sls($this->provider, ['SAMLRequest' => 'anything', 'Signature' => '']);

    expect(session('saml2.error'))->toBe(['error' => 'Unsigned logout message rejected']);
});

/*
| The vendor route accepts a POST as well. The toolkit only reads the
| HTTP-Redirect binding, so a POSTed message has no signature it could verify
| and is refused with everything else that has none.
*/
it('refuses a logout message posted to it', function () {
    sls($this->provider, method: 'post');

    expect(session('saml2.error'))->toBe(['error' => 'Unsigned logout message rejected']);
});

it('is on when an application\'s published config predates the setting', function () {
    config(['saml.security' => ['strict_request_binding' => true]]);

    sls($this->provider);

    expect(session('saml2.error'))->toBe(['error' => 'Unsigned logout message rejected']);
});

it('lets a signed message through to the toolkit, which checks the signature itself', function () {
    $this->withoutExceptionHandling();

    expect(fn () => sls($this->provider, ['Signature' => 'a-signature']))
        ->toThrow(OneLoginError::class, 'LogoutRequest/LogoutResponse not found');
});

it('stands aside for a provider that genuinely sends unsigned logouts', function () {
    config(['saml.security.want_logout_signed' => false]);

    $this->withoutExceptionHandling();

    expect(fn () => sls($this->provider))
        ->toThrow(OneLoginError::class, 'LogoutRequest/LogoutResponse not found');
});
