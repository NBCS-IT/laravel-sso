<?php

namespace NBCSIT\Sso\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use NBCSIT\Sso\SamlAuthenticator;
use NBCSIT\Sso\Support\SamlIdentity;
use NBCSIT\Sso\Users\EloquentUserResolver;

/**
 * Everything {@see SamlAuthenticator} needs to know about the consuming
 * application's users, and nothing else.
 *
 * A contract rather than a trait: a trait would force every application to edit
 * its own User model, and an application with a user store that is not Eloquent
 * at all could not use one. {@see EloquentUserResolver} is the default, and it
 * covers every consumer this package was written for.
 */
interface ResolvesSamlUsers
{
    /** The account this assertion belongs to, or null if there is none yet. */
    public function find(SamlIdentity $identity, ?string $email): ?Authenticatable;

    /** Create an account for an assertion that matched nothing. Null on failure. */
    public function provision(SamlIdentity $identity, string $email, ?string $name): ?Authenticatable;

    /** True when this account may sign in at all. */
    public function isActive(Authenticatable $user): bool;

    /** Write back the NameID, name, email and sign-in time. */
    public function sync(Authenticatable $user, SamlIdentity $identity, ?string $email, ?string $name): void;
}
