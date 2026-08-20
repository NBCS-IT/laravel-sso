<?php

namespace NBCSIT\Sso\Settings;

use NBCSIT\Saml2\Models\Tenant;
use NBCSIT\Sso\MultiCertificateOneLoginBuilder;
use Ramsey\Uuid\Uuid;
use Spatie\LaravelSettings\Settings;

/**
 * SAML behaviour that administrators own: which identity provider is used,
 * whether unknown users are provisioned, and how assertion attributes and
 * groups map on to local users and Spatie roles.
 *
 * **This class does not own the service provider's own metadata** — the
 * organisation name and URL, and the technical and support contacts. Those are
 * read by the vendor package's `config/saml2.php` to build this application's
 * SP metadata document, not by anything here, and they stay in the
 * application's own settings class. Deleting them because they used to live
 * beside these fields breaks the SP metadata endpoint.
 *
 * The Spatie group is `saml`. An application that already has a settings class
 * in that group must move it before installing this one — two classes sharing a
 * group is a silent read/write conflict. See `docs/adoption.md`.
 */
class SamlSettings extends Settings
{
    public bool $enabled;

    /** Create a local user the first time an unknown person authenticates. */
    public bool $provision_users;

    /** Replace the user's roles from the assertion's groups on every login. */
    public bool $sync_groups;

    /**
     * Sign the authentication and logout messages this application sends.
     *
     * One switch rather than three, because an identity provider that requires
     * a signed AuthnRequest requires signed logout messages too. Ignored while
     * there is no usable certificate — see
     * {@see MultiCertificateOneLoginBuilder}.
     */
    public bool $sign_requests;

    /** Sign the SP metadata document this application publishes. */
    public bool $sign_metadata;

    /** Assertion attribute holding the user's email address. */
    public string $email_attribute;

    /** Assertion attribute holding the user's display name. */
    public string $name_attribute;

    /** Assertion attribute holding the user's groups. */
    public string $groups_claim;

    /**
     * IdP group value => local role name. A group with no entry is ignored, so
     * an IdP that sends every group a user belongs to cannot create roles here.
     *
     * @var array<string, string>
     */
    public array $group_role_map;

    /** UUID of the saml2 tenant used for the default login route. */
    public ?string $default_uuid;

    public static function group(): string
    {
        return 'saml';
    }

    /**
     * The tenant the /login route should hand off to, or null when SAML has not
     * been configured yet — in which case login falls back to the local form.
     */
    public function activeTenant(): ?Tenant
    {
        if ($this->default_uuid === null || ! Uuid::isValid($this->default_uuid)) {
            return null;
        }

        return Tenant::query()->where('uuid', $this->default_uuid)->first();
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->activeTenant() !== null;
    }

    /**
     * Local role names for a set of IdP group values.
     *
     * @param  array<int, string>  $groups
     * @return list<string>
     */
    public function rolesForGroups(array $groups): array
    {
        $mapped = [];

        foreach ($groups as $group) {
            $role = $this->group_role_map[$group] ?? null;

            if (is_string($role) && $role !== '') {
                $mapped[] = $role;
            }
        }

        return array_values(array_unique($mapped));
    }
}
