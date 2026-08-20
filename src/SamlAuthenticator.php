<?php

namespace NBCSIT\Sso;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use NBCSIT\Saml2\Models\Tenant;
use NBCSIT\Sso\Contracts\ResolvesSamlUsers;
use NBCSIT\Sso\Enums\SamlLoginOutcome;
use NBCSIT\Sso\Groups\GroupSynchroniser;
use NBCSIT\Sso\Models\SamlAssertion;
use NBCSIT\Sso\Settings\SamlSettings;
use NBCSIT\Sso\Support\SamlIdentity;

/**
 * Turns a validated SAML assertion into a signed-in local user.
 *
 * Separate from the event listener so every branch — replay, provisioning off,
 * a deactivated account, group sync — can be tested directly.
 *
 * Everything user-shaped is delegated: the four operations on an account go
 * through {@see ResolvesSamlUsers}, and the role map through
 * {@see GroupSynchroniser}. What stays here is the order those things happen
 * in, which is the part that decides who gets signed in.
 */
class SamlAuthenticator
{
    public function __construct(
        private readonly SamlSettings $settings,
        private readonly ResolvesSamlUsers $users,
        private readonly GroupSynchroniser $groups,
    ) {}

    /**
     * The tenant is the provider whose assertion this is, and it is optional
     * only so that the sign-in logic stays callable without one. In the real
     * flow the listener always has it: the vendor package resolved it from the
     * URL before the toolkit ever looked at the response.
     */
    public function authenticate(SamlIdentity $identity, ?Tenant $tenant = null): SamlLoginOutcome
    {
        // Before anything else, because "switched off" has to mean it. The
        // setting stopped the middleware sending anybody to the identity
        // provider, but the assertion consumer stayed live and kept signing
        // people in — so an administrator switching single sign-on off to
        // contain an incident had not contained it.
        if (! $this->settings->enabled) {
            return SamlLoginOutcome::Disabled;
        }

        // `default_uuid` only decides which provider /login hands off to; every
        // row in the table is reachable at its own assertion consumer and is a
        // live trust anchor until this says otherwise. A provider that has been
        // decommissioned but not deleted is exactly the case.
        //
        // Read as an attribute rather than a property so that an application
        // that has not run the migration yet gets null, and null is in use.
        if ($tenant !== null && $tenant->getAttribute('enabled') === false) {
            return SamlLoginOutcome::TenantDisabled;
        }

        $refusal = $this->recordAssertion($identity);

        if ($refusal !== null) {
            return $refusal;
        }

        $email = $identity->attribute($this->settings->email_attribute);
        $name = $identity->attribute($this->settings->name_attribute);

        $user = $this->users->find($identity, $email);

        if ($user === null) {
            if (! $this->settings->provision_users) {
                return SamlLoginOutcome::NotProvisioned;
            }

            if ($email === null || $email === '') {
                return SamlLoginOutcome::MissingEmail;
            }

            $user = $this->users->provision($identity, $email, $name);

            if ($user === null) {
                return SamlLoginOutcome::ProvisioningFailed;
            }
        }

        if (! $this->users->isActive($user)) {
            return SamlLoginOutcome::Inactive;
        }

        $this->users->sync($user, $identity, $email, $name);

        if ($this->settings->sync_groups) {
            $this->syncRoles($user, $identity);
        }

        Auth::login($user);

        return SamlLoginOutcome::SignedIn;
    }

    /**
     * Claim this assertion's replay key, or name the reason the sign-in must
     * not go ahead. Null means it may.
     *
     * The unique index is what actually enforces this: two responses replayed
     * concurrently both pass an existence check, but only one insert survives.
     *
     * The key is the assertion ID wherever there is one — {@see
     * SamlIdentity::replayKey()} explains why the message ID is not good enough
     * on its own.
     */
    private function recordAssertion(SamlIdentity $identity): ?SamlLoginOutcome
    {
        $key = $identity->replayKey();

        if ($key === null) {
            return $this->unkeyedAssertion($identity);
        }

        try {
            SamlAssertion::query()->create([
                'request_id' => $key,
                'not_on_or_after' => $identity->notOnOrAfter,
            ]);
        } catch (QueryException) {
            return SamlLoginOutcome::Replayed;
        }

        return null;
    }

    /**
     * A response carrying neither an assertion ID nor a message ID leaves replay
     * detection nothing to key on.
     *
     * Refused by default. A SAML response without an assertion `ID` is
     * malformed rather than merely quirky, and signing somebody in from one
     * means accepting it again, and again, for as long as it is in date. The
     * escape hatch is there for an identity provider that cannot be fixed, and
     * it is loud: every sign-in it allows is a sign-in nothing can un-replay.
     */
    private function unkeyedAssertion(SamlIdentity $identity): ?SamlLoginOutcome
    {
        if (! config()->boolean('saml.security.allow_unkeyed_assertions', false)) {
            return SamlLoginOutcome::Unverifiable;
        }

        Log::warning('Accepted a SAML assertion with nothing to key replay detection on.', [
            'name_id' => $identity->nameId,
        ]);

        return null;
    }

    /**
     * Apply the group-to-role map.
     *
     * Only roles named in the map are added or removed — see
     * {@see GroupSynchroniser} for why that matters.
     */
    private function syncRoles(Authenticatable $user, SamlIdentity $identity): void
    {
        $groups = $identity->attributeValues($this->settings->groups_claim);

        $this->groups->sync(
            $user,
            $this->settings->rolesForGroups($groups),
            array_values(array_unique(array_values($this->settings->group_role_map))),
        );
    }
}
