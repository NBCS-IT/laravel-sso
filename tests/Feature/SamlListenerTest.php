<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use NBCSIT\Saml2\Auth as SamlAuth;
use NBCSIT\Saml2\Events\SignedIn;
use NBCSIT\Saml2\Events\SignedOut;
use NBCSIT\Saml2\Saml2User;
use NBCSIT\Sso\Enums\SamlLoginOutcome;
use NBCSIT\Sso\Exceptions\MissingSamlSession;
use NBCSIT\Sso\Listeners\HandleSamlSignIn;
use NBCSIT\Sso\Listeners\HandleSamlSignOut;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Models\SamlAssertion;
use NBCSIT\Sso\Tests\Fixtures\User;
use OneLogin\Saml2\Auth as OneLoginAuth;

/**
 * Fire the listener with a real SignedIn event, on a started session.
 *
 * The session is started explicitly because nothing in a unit test does it: in
 * an application the `saml.session` middleware group has already run by the
 * time the vendor package raises this event, and the listener refuses without
 * it.
 *
 * @param  array<string, array<int, string>>  $attributes
 */
function dispatchSignIn(
    string $nameId,
    array $attributes,
    mixed $messageId = '_msg-1',
    mixed $notOnOrAfter = null,
    bool $throwOnMessageId = false,
    bool $throwOnBase = false,
    mixed $assertionId = null,
    mixed $tenant = null,
): void {
    session()->start();

    dispatchSignInWithoutSession($nameId, $attributes, $messageId, $notOnOrAfter, $throwOnMessageId, $throwOnBase, $assertionId, $tenant);
}

/**
 * The same, on whatever session state the caller has left in place.
 *
 * The event is typed against the toolkit's own classes, and both need a parsed
 * IdP response to construct for real, so they are mocked down to the three
 * accessors the listener actually calls.
 *
 * @param  array<string, array<int, string>>  $attributes
 */
function dispatchSignInWithoutSession(
    string $nameId,
    array $attributes,
    mixed $messageId = '_msg-1',
    mixed $notOnOrAfter = null,
    bool $throwOnMessageId = false,
    bool $throwOnBase = false,
    mixed $assertionId = null,
    mixed $tenant = null,
): void {
    $samlUser = Mockery::mock(Saml2User::class);
    $samlUser->shouldReceive('getNameId')->andReturn($nameId);
    $samlUser->shouldReceive('getAttributes')->andReturn($attributes);

    $auth = Mockery::mock(SamlAuth::class);

    if ($tenant === 'throw') {
        $auth->shouldReceive('getTenant')->andThrow(new RuntimeException('no tenant available'));
    } else {
        $auth->shouldReceive('getTenant')->andReturn($tenant);
    }

    if ($throwOnMessageId) {
        $auth->shouldReceive('getLastMessageId')->andThrow(new RuntimeException('no message id available'));
    } else {
        $auth->shouldReceive('getLastMessageId')->andReturn($messageId);
    }

    if ($throwOnBase) {
        $auth->shouldReceive('getBase')->andThrow(new RuntimeException('no assertion available'));
    } else {
        $base = Mockery::mock(OneLoginAuth::class);
        $base->shouldReceive('getLastAssertionNotOnOrAfter')->andReturn($notOnOrAfter);
        $base->shouldReceive('getLastAssertionId')->andReturn($assertionId);
        $auth->shouldReceive('getBase')->andReturn($base);
    }

    app(HandleSamlSignIn::class)->handle(new SignedIn($samlUser, $auth));
}

beforeEach(function () {
    samlSettings([
        'enabled' => true,
        'provision_users' => true,
        'email_attribute' => 'email',
        'name_attribute' => 'name',
    ]);
});

it('signs the user in and clears the session markers', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au'], 'name' => ['A Person']], notOnOrAfter: now()->addMinutes(5)->timestamp);

    expect(Auth::check())->toBeTrue()
        ->and(session()->has(HandleSamlSignIn::SESSION_OUTCOME))->toBeFalse()
        ->and(session()->has(HandleSamlSignIn::SESSION_NAME_ID))->toBeFalse();
});

it('leaves the failure in the session when sign-in does not complete', function () {
    samlSettings(['provision_users' => false]);

    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']]);

    expect(Auth::check())->toBeFalse()
        ->and(session(HandleSamlSignIn::SESSION_OUTCOME))->toBe(SamlLoginOutcome::NotProvisioned->value)
        ->and(session(HandleSamlSignIn::SESSION_NAME_ID))->toBe('nid-1');
});

/*
| Replay detection is keyed on the assertion ID, which is inside the signed
| region — see SamlIdentity::replayKey(). These cover what the listener reads it
| from, and what it does when the toolkit reports nothing usable.
*/
it('keys replay detection on the assertion id rather than the message id', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], messageId: '_msg-1', assertionId: '_assert-1');

    expect(Auth::check())->toBeTrue()
        ->and(SamlAssertion::query()->pluck('request_id')->all())->toBe(['_assert-1']);
});

it('falls back to the message id when the toolkit reports no assertion id', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], messageId: '_msg-1', assertionId: null);

    expect(Auth::check())->toBeTrue()
        ->and(SamlAssertion::query()->pluck('request_id')->all())->toBe(['_msg-1']);
});

it('ignores a non-string assertion id', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], messageId: '_msg-1', assertionId: 12345);

    expect(SamlAssertion::query()->pluck('request_id')->all())->toBe(['_msg-1']);
});

it('ignores a blank assertion id', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], messageId: '_msg-1', assertionId: '');

    expect(SamlAssertion::query()->pluck('request_id')->all())->toBe(['_msg-1']);
});

it('refuses a sign-in when the toolkit reports neither id', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], throwOnMessageId: true);

    expect(Auth::check())->toBeFalse()
        ->and(SamlAssertion::query()->count())->toBe(0)
        ->and(session(HandleSamlSignIn::SESSION_OUTCOME))->toBe(SamlLoginOutcome::Unverifiable->value);
});

it('copes with a toolkit that cannot report an expiry', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], throwOnBase: true);

    expect(Auth::check())->toBeTrue()
        ->and(SamlAssertion::query()->firstOrFail()->not_on_or_after)->toBeNull();
});

it('ignores a non-string message id', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], messageId: 12345, assertionId: '_assert-1');

    expect(Auth::check())->toBeTrue()
        ->and(SamlAssertion::query()->pluck('request_id')->all())->toBe(['_assert-1']);
});

it('ignores a blank message id', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], messageId: '', assertionId: '_assert-1');

    expect(Auth::check())->toBeTrue()
        ->and(SamlAssertion::query()->pluck('request_id')->all())->toBe(['_assert-1']);
});

it('ignores an expiry that is not a timestamp', function () {
    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], notOnOrAfter: 'not-a-timestamp');

    expect(SamlAssertion::query()->firstOrFail()->not_on_or_after)->toBeNull();
});

it('accepts a numeric-string expiry', function () {
    $timestamp = now()->addMinutes(5)->timestamp;

    dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], notOnOrAfter: (string) $timestamp);

    expect(SamlAssertion::query()->firstOrFail()->not_on_or_after->timestamp)->toBe($timestamp);
});

describe('the sessionless assertion consumer', function () {
    /*
     * The last line of defence behind `saml.session`. An application can still
     * point `saml2.routesMiddleware` at a group of its own that has no session
     * in it, and the failure that produces is invisible: the sign-in succeeds,
     * the session goes out with the response, and the next guarded page bounces
     * back to the identity provider. A thrown exception names the cause; a
     * redirect loop does not.
     */
    it('refuses rather than authenticating into a session that is discarded', function () {
        expect(session()->isStarted())->toBeFalse();

        dispatchSignInWithoutSession('nid-1', ['email' => ['a@nbcs.nsw.edu.au'], 'name' => ['A Person']]);
    })->throws(MissingSamlSession::class);

    it('leaves nobody signed in when it refuses', function () {
        try {
            dispatchSignInWithoutSession('nid-1', ['email' => ['a@nbcs.nsw.edu.au'], 'name' => ['A Person']]);
        } catch (MissingSamlSession) {
            // The point of the test is what did not happen.
        }

        expect(Auth::check())->toBeFalse()
            ->and(User::query()->count())->toBe(0);
    });
});

describe('single logout', function () {
    it('ends the local session when the idp ends its own', function () {
        Auth::login(User::factory()->create());
        session([HandleSamlSignIn::SESSION_OUTCOME => 'replayed']);

        app(HandleSamlSignOut::class)->handle(new SignedOut);

        expect(Auth::check())->toBeFalse()
            ->and(session()->has(HandleSamlSignIn::SESSION_OUTCOME))->toBeFalse();
    });
});

/*
| `default_uuid` only decides which provider /login hands off to. Every row in
| the table has its own live assertion consumer, so switching one off has to be
| honoured here or it is not switched off at all.
*/
describe('the provider the assertion came from', function () {
    it('refuses one that has been switched off', function () {
        $tenant = IdentityProvider::factory()->create(['enabled' => false]);

        dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], tenant: $tenant);

        expect(Auth::check())->toBeFalse()
            ->and(session(HandleSamlSignIn::SESSION_OUTCOME))->toBe(SamlLoginOutcome::TenantDisabled->value);
    });

    it('signs in through one that is switched on', function () {
        $tenant = IdentityProvider::factory()->create(['enabled' => true]);

        dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], tenant: $tenant);

        expect(Auth::check())->toBeTrue();
    });

    it('copes with a toolkit that cannot report one', function () {
        dispatchSignIn('nid-1', ['email' => ['a@nbcs.nsw.edu.au']], tenant: 'throw');

        expect(Auth::check())->toBeTrue();
    });
});

it('is wired to the package events', function () {
    expect(Event::hasListeners(SignedIn::class))->toBeTrue()
        ->and(Event::hasListeners(SignedOut::class))->toBeTrue();
});
