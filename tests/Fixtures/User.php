<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * A user model for the package's own test suite.
 *
 * It exists so the package can be exercised without an application around it,
 * and it doubles as a check on the seam: a test that reaches for
 * `App\Models\User` has found somewhere the extraction is not finished.
 *
 * It carries `is_active` and `last_login_at` even though the package defaults
 * both column names to null, so one fixture can exercise both the configured
 * and the unconfigured path.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $saml_name_id
 * @property string|null $external_id
 * @property string|null $password
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles;

    /** The role no group map grants, and that group sync must never strip. */
    public const SUPER_ADMIN_ROLE = 'Super Admin';

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password', 'saml_name_id', 'external_id', 'is_active', 'last_login_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
