<?php

namespace NBCSIT\Sso\Enums;

/**
 * Why a SAML sign-in did or did not end with a session.
 *
 * The middleware turns anything other than {@see self::SignedIn} into a message
 * for the user, so each case carries the wording for that page.
 *
 * The wording says "this site" rather than naming an application, because this
 * enum ships to every consumer. An application that wants its own name in front
 * of a user can match on the case rather than rendering `message()`.
 */
enum SamlLoginOutcome: string
{
    case SignedIn = 'signed_in';
    case Disabled = 'disabled';
    case TenantDisabled = 'tenant_disabled';
    case Replayed = 'replayed';
    case Unverifiable = 'unverifiable';
    case MissingEmail = 'missing_email';
    case NotProvisioned = 'not_provisioned';
    case ProvisioningFailed = 'provisioning_failed';
    case Inactive = 'inactive';

    public function succeeded(): bool
    {
        return $this === self::SignedIn;
    }

    public function message(): string
    {
        return match ($this) {
            self::SignedIn => 'Signed in.',
            self::Disabled => 'Single sign-on is switched off on this site. Please contact IT.',
            self::TenantDisabled => 'This sign-in provider is no longer in use on this site. Please contact IT.',
            self::Replayed => 'This sign-in response has already been used. Please start again from the beginning.',
            self::Unverifiable => 'Your identity provider sent a sign-in response this site cannot check for reuse. Please contact IT.',
            self::MissingEmail => 'Your identity provider did not send an email address, which this site needs to identify you. Please contact IT.',
            self::NotProvisioned => 'You do not have an account on this site. Please contact IT to have one created.',
            self::ProvisioningFailed => 'Your account could not be created automatically. Please contact IT.',
            self::Inactive => 'Your account on this site has been deactivated. Please contact IT.',
        };
    }
}
