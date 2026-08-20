<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * An Eloquent model that is not an Authenticatable. Exists so the resolver's
 * refusal to hand one back is tested — the misconfiguration it stands for is
 * `saml.user.model` pointing at the wrong class.
 */
class NotAUser extends Model
{
    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'saml_name_id', 'password'];
}
