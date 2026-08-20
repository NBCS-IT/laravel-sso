<?php

use Illuminate\Contracts\Auth\Authenticatable;
use NBCSIT\Sso\Groups\GroupSynchroniser;
use NBCSIT\Sso\Support\SamlIdentity;
use NBCSIT\Sso\Tests\Fixtures\NotAUser;
use NBCSIT\Sso\Tests\Fixtures\RolelessUser;
use NBCSIT\Sso\Users\EloquentUserResolver;

/*
| What the package does when the application has pointed it at the wrong class.
| Every one of these would otherwise be a type error thrown from inside Eloquent
| or Spatie, during a login, naming nothing an administrator could act on.
*/

it('names the config key when the user model is not an Authenticatable', function () {
    config(['saml.user.model' => NotAUser::class]);

    NotAUser::query()->create([
        'name' => 'A Person',
        'email' => 'a.person@nbcs.nsw.edu.au',
        'saml_name_id' => 'name-id-abc',
    ]);

    expect(fn () => app(EloquentUserResolver::class)->find(new SamlIdentity('name-id-abc', []), null))
        ->toThrow(RuntimeException::class, "config('saml.user.model')");
});

it('says so when it is handed an account that is not an Eloquent model', function () {
    $stranger = new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };

    config(['saml.user.columns.active' => 'is_active']);

    expect(fn () => app(EloquentUserResolver::class)->isActive($stranger))
        ->toThrow(RuntimeException::class, 'not an Eloquent model');
});

it('refuses to synchronise groups onto a model without the Spatie trait', function () {
    $user = RolelessUser::query()->create([
        'name' => 'A Person',
        'email' => 'a.person@nbcs.nsw.edu.au',
    ]);

    expect(fn () => app(GroupSynchroniser::class)->sync($user, ['Network Team'], ['Network Team']))
        ->toThrow(RuntimeException::class, 'HasRoles');
});
