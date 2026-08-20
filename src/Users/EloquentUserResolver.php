<?php

namespace NBCSIT\Sso\Users;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NBCSIT\Sso\Contracts\ResolvesSamlUsers;
use NBCSIT\Sso\Models\SamlAccountLink;
use NBCSIT\Sso\Support\SamlIdentity;
use RuntimeException;
use Throwable;

/**
 * The default resolver: an Eloquent user model whose column names come from
 * `config('saml.user.columns')`.
 *
 * The matching order — NameID first, email second — is the security-relevant
 * part and is not configurable. The NameID is the identity the identity
 * provider guarantees; the email address is what connects an account somebody
 * created by hand to single sign-on the first time its owner signs in.
 */
class EloquentUserResolver implements ResolvesSamlUsers
{
    public function find(SamlIdentity $identity, ?string $email): ?Authenticatable
    {
        $user = $this->query()->where($this->column('name_id'), $identity->nameId)->first();

        if ($user !== null) {
            return $this->authenticatable($user);
        }

        if ($email === null || $email === '' || ! $this->mayLinkByEmail($email)) {
            return null;
        }

        return $this->authenticatable($this->byEmail($email)->first());
    }

    /**
     * Whether an address may claim an account that already exists.
     *
     * Matching on NameID is the identity the identity provider guarantees.
     * Matching on email is a convenience that treats the address in the
     * assertion as proof of ownership of whatever local account holds it, so
     * where the provider admits guests or lets people edit their own mail
     * attribute it is a way to capture an account, a privileged one included.
     *
     * An empty allow-list means any domain, because an application that signs
     * external people in legitimately has no single domain to name.
     */
    private function mayLinkByEmail(string $email): bool
    {
        $domains = config('saml.user.link_domains');

        if (! is_array($domains) || $domains === []) {
            return true;
        }

        $domain = Str::lower(Str::after($email, '@'));

        foreach ($domains as $allowed) {
            if (is_string($allowed) && Str::lower($allowed) === $domain) {
                return true;
            }
        }

        Log::warning('Refused to link a SAML assertion to a local account by email.', [
            'domain' => $domain,
        ]);

        return false;
    }

    public function provision(SamlIdentity $identity, string $email, ?string $name): ?Authenticatable
    {
        try {
            $attributes = [
                $this->column('name') => $name !== null && $name !== '' ? $name : Str::before($email, '@'),
                $this->column('email') => $email,
                $this->column('name_id') => $identity->nameId,

                // No password: a provisioned account must not be usable at the
                // application's local login form. The application is responsible
                // for refusing one — see the README.
                'password' => null,
            ];

            $active = $this->optionalColumn('active');

            if ($active !== null) {
                $attributes[$active] = true;
            }

            return $this->authenticatable($this->query()->create($attributes));
        } catch (Throwable $e) {
            Log::warning('Could not provision a SAML user.', [
                'name_id' => $identity->nameId,
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * With no active column configured there is nothing to refuse on, so every
     * account that exists may sign in.
     */
    public function isActive(Authenticatable $user): bool
    {
        $column = $this->optionalColumn('active');

        if ($column === null) {
            return true;
        }

        return (bool) $this->model($user)->getAttribute($column);
    }

    public function sync(Authenticatable $user, SamlIdentity $identity, ?string $email, ?string $name): void
    {
        $model = $this->model($user);

        // Read before the write: an account with nothing here is being bound to
        // an assertion for the first time, and that is the one event worth
        // keeping — see {@see SamlAccountLink}.
        $wasUnlinked = ((string) ($model->getAttribute($this->column('name_id')) ?? '')) === '';

        $attributes = [$this->column('name_id') => $identity->nameId];

        if ($name !== null && $name !== '') {
            $attributes[$this->column('name')] = $name;
        }

        // Never take an address another account already holds: two users would
        // then differ only by NameID, and whichever signed in second would take
        // over the first one's sign-in-by-email path.
        if ($email !== null && $email !== '' && ! $this->byEmail($email)->whereKeyNot($model->getKey())->exists()) {
            $attributes[$this->column('email')] = $email;
        }

        $lastLogin = $this->optionalColumn('last_login_at');

        if ($lastLogin !== null) {
            $attributes[$lastLogin] = now();
        }

        $model->forceFill($attributes)->save();

        // An account created for this assertion a moment ago already carries
        // the NameID and is nobody's account but this one's. What is worth
        // recording is the other case: an account that existed before, claimed
        // by the address in an assertion.
        if ($wasUnlinked && ! $model->wasRecentlyCreated) {
            $this->recordLink($model, $identity, $email);
        }
    }

    /**
     * Write down the binding, and say so in the log.
     */
    private function recordLink(Model $model, SamlIdentity $identity, ?string $email): void
    {
        SamlAccountLink::query()->create([
            'user_id' => $model->getKey(),
            'name_id' => $identity->nameId,
            'email' => $email,
            'matched_by' => 'email',
        ]);

        Log::notice('A SAML assertion was linked to an existing local account by email address.', [
            'user_id' => $model->getKey(),
            'name_id' => $identity->nameId,
            'email' => $email,
        ]);
    }

    /**
     * Addresses are matched without regard to case, which is what `lower()` is
     * for. The column name is a developer-set config value and nothing a
     * request can reach, and it still goes through the grammar's quoting on the
     * way into the statement.
     *
     * @return Builder<Model>
     */
    private function byEmail(string $email): Builder
    {
        $query = $this->query();
        $sql = 'lower('.$query->getGrammar()->wrap($this->column('email')).') = ?';

        // @phpstan-ignore argument.type (built from a quoted config value, never from a request)
        return $query->whereRaw($sql, [Str::lower($email)]);
    }

    /**
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        /** @var class-string<Model> $model */
        $model = config('saml.user.model');

        return $model::query();
    }

    /**
     * A misconfigured user model is worth a sentence naming the config key. The
     * alternative is a type error thrown from somewhere inside Eloquent.
     */
    private function authenticatable(?Model $user): ?Authenticatable
    {
        if ($user === null) {
            return null;
        }

        if (! $user instanceof Authenticatable) {
            throw new RuntimeException(
                'config(\'saml.user.model\') names '.$user::class.', which does not implement '.Authenticatable::class.'.',
            );
        }

        return $user;
    }

    /**
     * The counterpart: this resolver reads and writes columns, so an account it
     * is handed has to be an Eloquent model. A user store that is not Eloquent
     * needs its own {@see ResolvesSamlUsers} rather than this one.
     */
    private function model(Authenticatable $user): Model
    {
        if (! $user instanceof Model) {
            throw new RuntimeException(
                self::class.' was given '.$user::class.', which is not an Eloquent model.',
            );
        }

        return $user;
    }

    /**
     * A column the package cannot work without. Falls back to the shipped
     * default rather than producing SQL with an empty column name in it.
     */
    private function column(string $key): string
    {
        $column = $this->optionalColumn($key);

        return $column ?? ['name_id' => 'saml_name_id', 'email' => 'email', 'name' => 'name'][$key];
    }

    /**
     * A column whose absence switches a behaviour off — see `config/saml.php`.
     */
    private function optionalColumn(string $key): ?string
    {
        $column = config('saml.user.columns.'.$key);

        return is_string($column) && $column !== '' ? $column : null;
    }
}
