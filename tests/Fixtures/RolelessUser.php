<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A user model that is perfectly usable for signing in and has never heard of
 * Spatie Permission. Exists so the group synchroniser's refusal is tested.
 */
class RolelessUser extends Authenticatable
{
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email'];
}
