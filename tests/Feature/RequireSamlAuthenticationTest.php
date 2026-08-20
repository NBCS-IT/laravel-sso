<?php

use NBCSIT\Saml2\Models\Tenant;
use NBCSIT\Sso\Enums\SamlLoginOutcome;
use NBCSIT\Sso\Listeners\HandleSamlSignIn;
use NBCSIT\Sso\Settings\SamlSettings;
use NBCSIT\Sso\Tests\Fixtures\User;
use Ramsey\Uuid\Uuid;

function tenantFor(bool $enabled): Tenant
{
    $tenant = Tenant::query()->create([
        'uuid' => Uuid::uuid4()->toString(),
        'key' => 'Entra',
        'idp_entity_id' => 'urn:example:idp',
        'idp_login_url' => 'https://login.example.com/sso',
        'idp_logout_url' => '',
        'idp_x509_cert' => 'CERT',
        'metadata' => [],
    ]);

    $settings = app(SamlSettings::class);
    $settings->enabled = $enabled;
    $settings->default_uuid = $tenant->uuid;
    $settings->save();

    return $tenant;
}

it('lets a signed-in user through', function () {
    $this->actingAs(User::factory()->create())->get(route('guarded'))->assertOk();
});

/*
| Deactivation has to bite before the next sign-in or it does not bite: the
| account is only checked while an assertion is being consumed, and somebody
| already signed in has no more assertions coming.
*/
describe('an account deactivated mid-session', function () {
    it('is stopped on its very next request', function () {
        config(['saml.user.columns.active' => 'is_active']);

        $response = $this->actingAs(User::factory()->inactive()->create())->get(route('guarded'));

        $response->assertForbidden();
        expect($response->getContent())->toContain('deactivated');
    });

    it('is signed out rather than merely refused', function () {
        config(['saml.user.columns.active' => 'is_active']);

        $this->actingAs(User::factory()->inactive()->create())->get(route('guarded'))->assertForbidden();

        $this->assertGuest();
    });

    it('is not a state the package can see with no active column configured', function () {
        expect(config('saml.user.columns.active'))->toBeNull();

        $this->actingAs(User::factory()->inactive()->create())->get(route('guarded'))->assertOk();
    });
});

it('sends a guest to the local form when no identity provider is configured', function () {
    $this->get(route('guarded'))->assertRedirect(route('local.login'));
});

it('sends a guest to the local form when single sign-on is switched off', function () {
    tenantFor(enabled: false);

    $this->get(route('guarded'))->assertRedirect(route('local.login'));
});

it('sends a guest to the identity provider when single sign-on is on', function () {
    $tenant = tenantFor(enabled: true);

    $response = $this->get(route('guarded'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('saml2/'.$tenant->uuid.'/login');
});

it('remembers where the guest was heading', function () {
    tenantFor(enabled: true);

    $response = $this->get(route('guarded'));

    expect($response->headers->get('Location'))->toContain(urlencode(route('guarded')));
});

it('explains a failed assertion instead of bouncing back to the idp', function () {
    tenantFor(enabled: true);

    $response = $this->withSession([HandleSamlSignIn::SESSION_OUTCOME => SamlLoginOutcome::NotProvisioned->value])
        ->get(route('guarded'));

    $response->assertForbidden();
    expect($response->getContent())->toContain('do not have an account');
});

it('clears the failure so a fresh attempt is not blocked by it', function () {
    tenantFor(enabled: true);

    $this->withSession([HandleSamlSignIn::SESSION_OUTCOME => SamlLoginOutcome::Replayed->value])
        ->get(route('guarded'))
        ->assertForbidden();

    // The marker is gone, so the next request goes back to the IdP.
    $this->get(route('guarded'))->assertRedirect();
});

it('ignores an unrecognised outcome in the session', function () {
    $this->withSession([HandleSamlSignIn::SESSION_OUTCOME => 'not-a-real-outcome'])
        ->get(route('guarded'))
        ->assertRedirect(route('local.login'));
});

it('ignores a signed-in outcome left in the session', function () {
    $this->withSession([HandleSamlSignIn::SESSION_OUTCOME => SamlLoginOutcome::SignedIn->value])
        ->get(route('guarded'))
        ->assertRedirect(route('local.login'));
});
