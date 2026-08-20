<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NBCSIT\Sso\Enums\SamlLoginOutcome;
use NBCSIT\Sso\Models\SamlAccountLink;
use NBCSIT\Sso\Models\SamlAssertion;
use NBCSIT\Sso\SamlAuthenticator;
use NBCSIT\Sso\Support\SamlIdentity;
use NBCSIT\Sso\Tests\Fixtures\User;
use Spatie\Permission\Models\Role;

const EMAIL_CLAIM = 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress';
const NAME_CLAIM = 'http://schemas.microsoft.com/identity/claims/displayname';
const GROUPS_CLAIM = 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups';

function assertion(array $overrides = []): SamlIdentity
{
    return new SamlIdentity(
        nameId: $overrides['nameId'] ?? 'name-id-abc',
        attributes: $overrides['attributes'] ?? [
            EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au'],
            NAME_CLAIM => ['Alex Staff'],
        ],
        messageId: array_key_exists('messageId', $overrides) ? $overrides['messageId'] : '_msg-'.uniqid(),
        notOnOrAfter: $overrides['notOnOrAfter'] ?? now()->addMinutes(5),
        assertionId: $overrides['assertionId'] ?? null,
    );
}

function authenticate(SamlIdentity $identity): SamlLoginOutcome
{
    return app(SamlAuthenticator::class)->authenticate($identity);
}

beforeEach(function () {
    // Off out of the box, so that a fresh install cannot be signed into before
    // anybody has configured an identity provider. Every test below is about
    // what happens once it is on.
    samlSettings(['enabled' => true]);
});

/*
| Switching single sign-on off on the settings screen used to stop the middleware
| sending anybody to the identity provider while leaving the assertion consumer
| live — so an administrator switching it off to contain an incident had not
| contained it.
*/
it('refuses to sign anybody in while single sign-on is switched off', function () {
    samlSettings(['enabled' => false, 'provision_users' => true]);

    expect(authenticate(assertion()))->toBe(SamlLoginOutcome::Disabled);

    expect(User::query()->count())->toBe(0)
        ->and(SamlAssertion::query()->count())->toBe(0);
    $this->assertGuest();
});

it('provisions and signs in an unknown user', function () {
    samlSettings(['provision_users' => true]);

    expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);

    $user = User::query()->where('saml_name_id', 'name-id-abc')->firstOrFail();

    expect($user->email)->toBe('staff@nbcs.nsw.edu.au')
        ->and($user->name)->toBe('Alex Staff')
        ->and($user->password)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and(Auth::id())->toBe($user->getKey());
});

it('names a provisioned user from their address when the idp sends no display name', function () {
    samlSettings(['provision_users' => true]);

    authenticate(assertion(['attributes' => [EMAIL_CLAIM => ['a.person@nbcs.nsw.edu.au']]]));

    expect(User::query()->firstOrFail()->name)->toBe('a.person');
});

it('refuses an unknown user when provisioning is off', function () {
    samlSettings(['provision_users' => false]);

    expect(authenticate(assertion()))->toBe(SamlLoginOutcome::NotProvisioned);

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

it('refuses to provision without an email address', function () {
    samlSettings(['provision_users' => true]);

    expect(authenticate(assertion(['attributes' => [NAME_CLAIM => ['Alex Staff']]])))
        ->toBe(SamlLoginOutcome::MissingEmail);

    $this->assertGuest();
});

it('reports a failure, and logs it, when the account cannot be created', function () {
    samlSettings(['provision_users' => true]);

    // Stand in for whatever makes the insert fail in production — a unique
    // index losing a race, or the database being unavailable.
    Event::listen('eloquent.creating: '.User::class, function () {
        throw new RuntimeException('database is on fire');
    });

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'Could not provision'));

    expect(authenticate(assertion()))->toBe(SamlLoginOutcome::ProvisioningFailed);

    $this->assertGuest();
});

it('signs in an existing account rather than provisioning a duplicate', function () {
    samlSettings(['provision_users' => true]);

    $existing = User::factory()->create(['email' => 'staff@nbcs.nsw.edu.au', 'saml_name_id' => null]);

    expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);

    expect(User::query()->count())->toBe(1);
    $this->assertAuthenticatedAs($existing->fresh());
});

it('matches an existing user by name id', function () {
    $user = User::factory()->samlOnly('name-id-abc')->create();

    expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);

    $this->assertAuthenticatedAs($user->fresh());
});

it('connects a hand-made account to single sign-on by email on first login', function () {
    $user = User::factory()->create(['email' => 'staff@nbcs.nsw.edu.au', 'saml_name_id' => null]);

    expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);

    expect($user->fresh()->saml_name_id)->toBe('name-id-abc');
});

it('matches an existing account by email regardless of case', function () {
    $user = User::factory()->create(['email' => 'staff@nbcs.nsw.edu.au']);

    authenticate(assertion(['attributes' => [EMAIL_CLAIM => ['STAFF@NBCS.NSW.EDU.AU']]]));

    $this->assertAuthenticatedAs($user->fresh());
});

it('prefers the name id over the email address', function () {
    $byNameId = User::factory()->samlOnly('name-id-abc')->create(['email' => 'old.address@nbcs.nsw.edu.au']);
    User::factory()->create(['email' => 'staff@nbcs.nsw.edu.au']);

    authenticate(assertion());

    $this->assertAuthenticatedAs($byNameId->fresh());
});

it('keeps the profile in step with the assertion', function () {
    $user = User::factory()->samlOnly('name-id-abc')->create([
        'name' => 'Old Name',
        'email' => 'old@nbcs.nsw.edu.au',
    ]);

    authenticate(assertion());

    expect($user->fresh()->name)->toBe('Alex Staff')
        ->and($user->fresh()->email)->toBe('staff@nbcs.nsw.edu.au');
});

it('will not overwrite an email address that another account already holds', function () {
    $user = User::factory()->samlOnly('name-id-abc')->create(['email' => 'mine@nbcs.nsw.edu.au']);
    User::factory()->create(['email' => 'staff@nbcs.nsw.edu.au']);

    expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);

    expect($user->fresh()->email)->toBe('mine@nbcs.nsw.edu.au');
});

it('leaves the name alone when the idp sends none', function () {
    $user = User::factory()->samlOnly('name-id-abc')->create(['name' => 'Existing Name']);

    authenticate(assertion(['attributes' => [EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au']]]));

    expect($user->fresh()->name)->toBe('Existing Name');
});

/*
| The three configurable columns. `active` and `last_login_at` are null out of
| the box because no sibling project has either column, so both the configured
| and the unconfigured path are covered here — the deactivated-account refusal
| is a security behaviour, and it must not rot just because most consumers do
| not switch it on.
*/
describe('the configurable user columns', function () {
    it('refuses a deactivated account when an active column is configured', function () {
        config(['saml.user.columns.active' => 'is_active']);

        User::factory()->samlOnly('name-id-abc')->inactive()->create();

        expect(authenticate(assertion()))->toBe(SamlLoginOutcome::Inactive);

        $this->assertGuest();
    });

    it('signs a deactivated account in when no active column is configured', function () {
        // Not an oversight: with nothing to refuse on, "deactivated" is not a
        // state this package can see.
        expect(config('saml.user.columns.active'))->toBeNull();

        $user = User::factory()->samlOnly('name-id-abc')->inactive()->create();

        expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);

        $this->assertAuthenticatedAs($user->fresh());
    });

    it('provisions with the active flag set when the column is configured', function () {
        config(['saml.user.columns.active' => 'is_active']);
        samlSettings(['provision_users' => true]);

        authenticate(assertion());

        expect(User::query()->firstOrFail()->is_active)->toBeTrue();
    });

    it('records the sign-in time when a column is configured for it', function () {
        config(['saml.user.columns.last_login_at' => 'last_login_at']);

        $user = User::factory()->samlOnly('name-id-abc')->create();

        authenticate(assertion());

        expect($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('writes no sign-in time when no column is configured for it', function () {
        expect(config('saml.user.columns.last_login_at'))->toBeNull();

        $user = User::factory()->samlOnly('name-id-abc')->create();

        expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);

        expect($user->fresh()->last_login_at)->toBeNull();
    });

    it('matches and provisions on the name id column it is told to use', function () {
        config(['saml.user.columns.name_id' => 'external_id']);
        samlSettings(['provision_users' => true]);

        $existing = User::factory()->create(['external_id' => 'name-id-abc', 'saml_name_id' => null]);

        expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);

        $this->assertAuthenticatedAs($existing->fresh());
        expect($existing->fresh()->saml_name_id)->toBeNull();
    });
});

/*
| Matching on NameID is the identity the provider guarantees. Matching on email
| treats the address in the assertion as proof of ownership of whatever local
| account holds it, which is a way to capture an account wherever the provider
| admits guests or lets people edit their own mail attribute.
*/
describe('linking by email address', function () {
    it('is open to any domain out of the box', function () {
        expect(config('saml.user.link_domains'))->toBe([]);

        $user = User::factory()->create(['email' => 'guest@partner.example', 'saml_name_id' => null]);

        expect(authenticate(assertion(['attributes' => [EMAIL_CLAIM => ['guest@partner.example']]])))
            ->toBe(SamlLoginOutcome::SignedIn);

        expect($user->fresh()->saml_name_id)->toBe('name-id-abc');
    });

    it('refuses an address outside the allow-list once one is set', function () {
        config(['saml.user.link_domains' => ['nbcs.nsw.edu.au']]);
        samlSettings(['provision_users' => false]);

        $user = User::factory()->create(['email' => 'guest@partner.example', 'saml_name_id' => null]);

        expect(authenticate(assertion(['attributes' => [EMAIL_CLAIM => ['guest@partner.example']]])))
            ->toBe(SamlLoginOutcome::NotProvisioned);

        expect($user->fresh()->saml_name_id)->toBeNull();
        $this->assertGuest();
    });

    it('allows an address inside it, whatever the case', function () {
        config(['saml.user.link_domains' => ['NBCS.NSW.EDU.AU']]);

        $user = User::factory()->create(['email' => 'staff@nbcs.nsw.edu.au', 'saml_name_id' => null]);

        expect(authenticate(assertion()))->toBe(SamlLoginOutcome::SignedIn);
        $this->assertAuthenticatedAs($user->fresh());
    });

    it('never restricts a match on name id, which the provider guarantees', function () {
        config(['saml.user.link_domains' => ['nbcs.nsw.edu.au']]);

        $user = User::factory()->samlOnly('name-id-abc')->create(['email' => 'guest@partner.example']);

        expect(authenticate(assertion(['attributes' => [EMAIL_CLAIM => ['guest@partner.example']]])))
            ->toBe(SamlLoginOutcome::SignedIn);

        $this->assertAuthenticatedAs($user->fresh());
    });

    it('records the binding, because nothing else about it leaves a trace', function () {
        $user = User::factory()->create(['email' => 'staff@nbcs.nsw.edu.au', 'saml_name_id' => null]);

        authenticate(assertion());

        $link = SamlAccountLink::query()->sole();

        expect($link->user_id)->toBe($user->getKey())
            ->and($link->name_id)->toBe('name-id-abc')
            ->and($link->email)->toBe('staff@nbcs.nsw.edu.au')
            ->and($link->matched_by)->toBe('email');
    });

    it('records nothing for an account provisioned for the assertion', function () {
        // It carries the NameID from the moment it exists and is nobody else's
        // account, so there is no claim to record.
        samlSettings(['provision_users' => true]);

        authenticate(assertion());

        expect(SamlAccountLink::query()->count())->toBe(0);
    });

    it('writes one row per account, not one per sign-in', function () {
        User::factory()->create(['email' => 'staff@nbcs.nsw.edu.au', 'saml_name_id' => null]);

        authenticate(assertion());
        Auth::logout();
        authenticate(assertion());

        expect(SamlAccountLink::query()->count())->toBe(1);
    });
});

describe('replay protection', function () {
    beforeEach(function () {
        samlSettings(['provision_users' => true]);
    });

    it('records the assertion it consumed', function () {
        authenticate(assertion(['messageId' => '_msg-once']));

        expect(SamlAssertion::query()->where('request_id', '_msg-once')->exists())->toBeTrue();
    });

    it('refuses the same assertion twice', function () {
        expect(authenticate(assertion(['messageId' => '_msg-once'])))->toBe(SamlLoginOutcome::SignedIn);

        Auth::logout();

        expect(authenticate(assertion(['messageId' => '_msg-once'])))->toBe(SamlLoginOutcome::Replayed);
        $this->assertGuest();
    });

    /*
    | The <samlp:Response> envelope is not covered by the signature when the IdP
    | signs the assertion only, which is Entra ID's default and the configuration
    | this package settles for. So the envelope's ID can be changed freely, and
    | keying on it would let the same signed assertion in as often as it is in
    | date. The assertion ID cannot be changed without breaking the signature.
    */
    it('keys on the assertion id in preference to the message id', function () {
        authenticate(assertion(['messageId' => '_msg-1', 'assertionId' => '_assert-1']));

        expect(SamlAssertion::query()->pluck('request_id')->all())->toBe(['_assert-1']);
    });

    it('refuses the same assertion re-enveloped under a fresh message id', function () {
        expect(authenticate(assertion(['messageId' => '_msg-1', 'assertionId' => '_assert-1'])))
            ->toBe(SamlLoginOutcome::SignedIn);

        Auth::logout();

        expect(authenticate(assertion(['messageId' => '_msg-2', 'assertionId' => '_assert-1'])))
            ->toBe(SamlLoginOutcome::Replayed);
        $this->assertGuest();
    });

    it('falls back to the message id when there is no assertion id', function () {
        authenticate(assertion(['messageId' => '_msg-1', 'assertionId' => null]));

        expect(SamlAssertion::query()->pluck('request_id')->all())->toBe(['_msg-1']);
    });

    it('refuses a response with nothing to key replay detection on', function () {
        expect(authenticate(assertion(['messageId' => null])))->toBe(SamlLoginOutcome::Unverifiable);

        expect(SamlAssertion::query()->count())->toBe(0);
        $this->assertGuest();
    });

    it('refuses a response whose only ids are blank', function () {
        expect(authenticate(assertion(['messageId' => '', 'assertionId' => ''])))
            ->toBe(SamlLoginOutcome::Unverifiable);

        $this->assertGuest();
    });

    it('signs in without a key, loudly, when an application has asked it to', function () {
        config(['saml.security.allow_unkeyed_assertions' => true]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'nothing to key replay detection on'));

        expect(authenticate(assertion(['messageId' => null])))->toBe(SamlLoginOutcome::SignedIn);
        expect(SamlAssertion::query()->count())->toBe(0);
    });

    it('prunes assertions that can no longer be valid', function () {
        SamlAssertion::factory()->create(['not_on_or_after' => now()->subDays(3)]);
        $current = SamlAssertion::factory()->create(['not_on_or_after' => now()->addMinutes(5)]);

        $this->artisan('model:prune', ['--model' => [SamlAssertion::class]])->assertSuccessful();

        expect(SamlAssertion::query()->pluck('id')->all())->toBe([$current->id]);
    });

    /*
    | A response with no SubjectConfirmationData/@NotOnOrAfter records a null
    | expiry, and `NULL < ?` is never true — so before the fallback on age these
    | rows were kept for ever.
    */
    it('prunes undated assertions once they are too old to matter', function () {
        $old = SamlAssertion::factory()->create(['not_on_or_after' => null]);
        $old->forceFill(['created_at' => now()->subDays(8)])->save();

        $recent = SamlAssertion::factory()->create(['not_on_or_after' => null]);

        $this->artisan('model:prune', ['--model' => [SamlAssertion::class]])->assertSuccessful();

        expect(SamlAssertion::query()->pluck('id')->all())->toBe([$recent->id]);
    });
});

describe('group to role mapping', function () {
    beforeEach(function () {
        Role::findOrCreate('Network Team', 'web');
        Role::findOrCreate('Facilities', 'web');
        Role::findOrCreate(User::SUPER_ADMIN_ROLE, 'web');

        samlSettings([
            'provision_users' => true,
            'sync_groups' => true,
            'group_role_map' => [
                'group-network-oid' => 'Network Team',
                'group-facilities-oid' => 'Facilities',
            ],
        ]);
    });

    it('grants the role a mapped group confers', function () {
        authenticate(assertion(['attributes' => [
            EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au'],
            GROUPS_CLAIM => ['group-network-oid'],
        ]]));

        expect(User::query()->firstOrFail()->hasRole('Network Team'))->toBeTrue();
    });

    it('grants every mapped group in the assertion', function () {
        authenticate(assertion(['attributes' => [
            EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au'],
            GROUPS_CLAIM => ['group-network-oid', 'group-facilities-oid'],
        ]]));

        expect(User::query()->firstOrFail()->getRoleNames()->all())
            ->toEqualCanonicalizing(['Network Team', 'Facilities']);
    });

    it('ignores a group with no mapping', function () {
        authenticate(assertion(['attributes' => [
            EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au'],
            GROUPS_CLAIM => ['group-unmapped-oid'],
        ]]));

        expect(User::query()->firstOrFail()->getRoleNames())->toBeEmpty();
    });

    it('removes a mapped role the user has lost', function () {
        $user = User::factory()->samlOnly('name-id-abc')->create();
        $user->assignRole('Network Team');

        authenticate(assertion(['attributes' => [
            EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au'],
            GROUPS_CLAIM => [],
        ]]));

        expect($user->fresh()->hasRole('Network Team'))->toBeFalse();
    });

    it('leaves a role granted by hand alone', function () {
        $user = User::factory()->samlOnly('name-id-abc')->create();
        $user->assignRole(User::SUPER_ADMIN_ROLE);

        authenticate(assertion(['attributes' => [
            EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au'],
            GROUPS_CLAIM => ['group-network-oid'],
        ]]));

        // Super Admin is not in the map, so group sync must not strip it.
        expect($user->fresh()->hasRole(User::SUPER_ADMIN_ROLE))->toBeTrue()
            ->and($user->fresh()->hasRole('Network Team'))->toBeTrue();
    });

    it('does not touch roles at all when group sync is off', function () {
        samlSettings(['sync_groups' => false]);

        $user = User::factory()->samlOnly('name-id-abc')->create();
        $user->assignRole('Network Team');

        authenticate(assertion(['attributes' => [
            EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au'],
            GROUPS_CLAIM => [],
        ]]));

        expect($user->fresh()->hasRole('Network Team'))->toBeTrue();
    });

    it('is idempotent across repeated logins', function () {
        $attributes = [EMAIL_CLAIM => ['staff@nbcs.nsw.edu.au'], GROUPS_CLAIM => ['group-network-oid']];

        authenticate(assertion(['attributes' => $attributes]));
        Auth::logout();
        authenticate(assertion(['attributes' => $attributes]));

        expect(User::query()->firstOrFail()->getRoleNames()->all())->toBe(['Network Team']);
    });
});
