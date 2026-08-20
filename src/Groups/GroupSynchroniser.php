<?php

namespace NBCSIT\Sso\Groups;

use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * Applies the group-to-role map to a user, and leaves every other role alone.
 *
 * The managed/granted split is the whole class. A role held by hand and not
 * named in the map — Super Admin, say, which no identity provider group should
 * ever confer — is untouched, rather than being stripped on the user's next
 * login. Anything that looks like a shortcut here (`syncRoles()`, for instance)
 * removes that guarantee.
 *
 * A concrete class rather than a contract: Spatie Permission is a hard
 * requirement, so an interface would have exactly one implementation. An
 * application that wants different behaviour rebinds this class in the
 * container, which is the same override an interface would have bought.
 */
class GroupSynchroniser
{
    /**
     * @param  list<string>  $granted  Local role names the assertion's groups map to
     * @param  list<string>  $managed  Every role name the map could grant
     */
    public function sync(Authenticatable $user, array $granted, array $managed): void
    {
        // Spatie Permission is a hard requirement of this package, but nothing
        // stops an application pointing `saml.user.model` at a model that never
        // got the trait. Saying so beats a fatal error inside a login.
        if (! method_exists($user, 'hasRole') || ! method_exists($user, 'assignRole') || ! method_exists($user, 'removeRole')) {
            throw new RuntimeException(
                $user::class.' must use '.HasRoles::class.' for group synchronisation. '
                    .'Add the trait, or switch `sync_groups` off on the SAML settings screen.',
            );
        }

        foreach ($managed as $name) {
            $role = $this->role($name);

            if (in_array($name, $granted, true)) {
                // A name in the map with no role behind it is passed to Spatie
                // as it was before this package existed, so the misconfiguration
                // still surfaces rather than being swallowed here.
                if (! $user->hasRole($role ?? $name)) {
                    $user->assignRole($role ?? $name);
                }

                continue;
            }

            if ($role !== null && $user->hasRole($role)) {
                $user->removeRole($role);
            }
        }
    }

    /**
     * Resolved under `config('saml.guard')` rather than by name alone: Spatie
     * throws when a role of that name exists under a different guard, which a
     * single-guard application never sees and a multi-guard one hits on the
     * first login.
     */
    private function role(string $name): ?RoleContract
    {
        /** @var RoleContract|null */
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', config('saml.guard'))
            ->first();
    }
}
