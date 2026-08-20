<?php

namespace NBCSIT\Sso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use NBCSIT\Sso\Users\EloquentUserResolver;

/**
 * The moment an assertion first bound itself to a local account.
 *
 * Written once per account, by {@see EloquentUserResolver}. After it the
 * account carries a NameID and every later sign-in matches on that, so there is
 * nothing more to record — which is exactly why this one row matters: it is the
 * only point at which an email address in an assertion turned into ownership of
 * an account that already existed.
 *
 * Deliberately not prunable. It is the evidence.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name_id
 * @property string|null $email
 * @property string $matched_by
 * @property Carbon|null $created_at
 */
class SamlAccountLink extends Model
{
    protected $fillable = ['user_id', 'name_id', 'email', 'matched_by'];
}
