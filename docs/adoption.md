# Adopting `nbcsit/laravel-sso`

There are two paths through this document and they are kept apart on purpose, because conflating
them is how somebody runs the wrong migration.

- **[Path A](#path-a--an-application-with-no-saml)** — the application has no SAML today. Short.
- **[Path B](#path-b--replacing-an-existing-saml-implementation)** — the application already has a
  SAML implementation. **This is a data migration, not an install.**

Both paths end at the same instruction, so it is here at the top as well: **back the database up
first, and schedule the migration window deliberately.** Nothing below is reversible by pressing
undo.

---

## Path A — an application with no SAML

1. Add both repository entries and require the package (see the README).
2. `php artisan vendor:publish --provider="Slides\Saml2\ServiceProvider"` — the vendor package
   registers its routes from `config/saml2.php` before it merges its own defaults, so that file has
   to exist.
3. `php artisan vendor:publish --tag=saml-config`.
4. `php artisan vendor:publish --tag=saml-migrations` — adds `saml_name_id` to `users`. If you want
   the deactivated-account refusal or a sign-in timestamp, add those columns here too and name them
   in `config/saml.php`.
5. `php artisan vendor:publish --tag=saml-admin` — the three controllers and three views.
6. **Add `Illuminate\Foundation\Auth\Access\AuthorizesRequests` to your base controller** if it is
   not there. Every published controller opens with `$this->authorize(config('saml.gate'))`, and
   Laravel's skeleton has not included the trait on `App\Http\Controllers\Controller` since 11 — so
   without it the screens fatal with `Call to undefined method authorize()` the first time anybody
   opens one.
7. Wire routes for the published controllers, under the names the views use
   (`admin.settings.saml.*`; the full list is in this package's `tests/TestCase.php`). Restyling the
   views is expected; **keep the `status`, `error` and `warnings` flash blocks at the top of each
   one.** The controllers report only by flashing and redirecting back, so a screen with nowhere for
   a flash to land makes a failure indistinguishable from a button that does nothing.
8. Apply the `saml.auth` middleware where you want it. Nothing to do about CSRF or sessions on
   `saml2/*` — **leave `saml2.routesMiddleware` empty** and the package's own `saml.session` group
   covers both; see the README. Open the published `config/saml2.php` and check the value rather than
   assuming, because a file that already existed is not overwritten by publishing: `['web']` there
   verifies CSRF tokens, and the identity provider has none to send.
9. Implement the local-login refusal for accounts with `password = null`. This is not optional — see
   the README.
10. `php artisan migrate`.
11. Open the settings screen, add the identity provider from its metadata URL, choose it, and switch
    single sign-on on.
12. If the identity provider wants signed requests, run `php artisan saml:generate-certificate
    --primary`, **fix the certificate directory's ownership** — see
    [the certificate directory](#the-certificate-directory-has-two-writers) below, which Path A hits
    on the first generate — give the identity provider this application's metadata, and only then
    switch signing on. In that order: signing before it has the certificate makes it reject
    everything.

---

## Path B — replacing an existing SAML implementation

Written from the first migration onto the package. The collisions below were gathered by reading the
sibling projects and are now corrected by what actually happened; the step-by-step at the end is the
order that worked.

### Who this applies to

An application on an **earlier generation of this implementation** — the ancestor this package was
rewritten from — does not lack a SAML implementation. It will already declare
`nbcsit/laravel-saml2`, `laravel/framework: ^13.0`, `spatie/laravel-permission` and
`spatie/laravel-settings`, so the package's *requirements* are nearly met. That is the easy part.

The exception is the first of those. `nbcsit/laravel-saml2` is NBCS IT's fork of the archived
`24slides/laravel-saml2`, and this package now requires the maintained `scaler-tech/laravel-saml2`
instead, carrying the fork's security fixes itself. Remove the fork and its `repositories` entry when
you require this package, and rename `NBCSIT\Saml2` to `Slides\Saml2` wherever the application names
it — see the README's upgrade notes.

### Pre-flight audit

Do all of this and write the answers down before changing a line. Every item on it earned its place;
in the first migration the two flagged as likely — the extra attribute mappings, and an unusable
certificate — were both real.

- [ ] **Inventory the existing `App\Settings\SamlSettings`** — every field and its current value in
      each environment. You are about to split this class in two.
- [ ] **Dump `saml_attribute_mappings` and `saml_group_mappings`.** Check that nothing in them falls
      outside what the package's three settings fields can hold: `email_attribute`, `name_attribute`
      and `group_role_map`. **A project that maps more attributes than those three loses the extra
      ones**, and this is the audit that catches it before rather than after.
- [ ] **Diff the existing `saml_assertions` schema against the package's.** See below — they are not
      the same, and the difference is load-bearing.
- [ ] **Read `config/saml2.php`, and read `routesMiddleware` in particular.** Publishing will not
      change it. See collision 6.
- [ ] **List every route and middleware group that names `App\Http\Middleware\NBCSSAML`.**
- [ ] **List everything that names `NBCSIT\Saml2`** — listeners, facade aliases, scripts that run
      `vendor:publish --provider`. The vendor classes are `Slides\Saml2` again.
- [ ] **Check `config/saml2.php` for `strictRequestBinding` and `wantLogoutSigned`.** Neither is read
      any more; their replacements are in `config/saml.php`, under `security`.
- [ ] **List every already-run migration that creates something the package now owns** — a table, a
      settings group, a file on disk. Every one of them has to be emptied; see collision 2.
- [ ] **Confirm whether the project manages its own SP signing certificate** (`SamlSigningCertController`,
      `CreateSamlSpCertificateRequest`, a `store_saml_cert_if_exists` migration). If it does, this
      package is a feature regression until that is dealt with — see below.
- [ ] **Check for a project-specific authorisation model** — a `SamlPolicy` and an
      `add_saml_permissions` migration are common, and they decide what `config('saml.gate')` should
      be set to.

### The collisions

#### 1. Two settings classes claiming the Spatie group `saml`

Both projects have an `App\Settings\SamlSettings` with `group() === 'saml'`, carrying the six
org/contact fields plus `provision_users`, `sync_groups`, `groups_claim`, `default_uuid` and a
static `active_tenant()` that is this package's `activeTenant()` in an earlier form.

Two classes in one group is a silent read/write conflict — not an error, just wrong values.

**Resolution.** Split the application's class: the six SP-metadata fields stay in the application (in
a class grouped as something else, `saml_sp` for instance, or folded into an existing settings
class); the package's eight fields are dropped from it.

**That takes three settings migrations, not one.** A rename alone works on an existing database and
breaks a fresh one, where there is nothing to rename; and the package's own settings migration then
throws `SettingAlreadyExists` on an existing database for every field the old class already put in
the `saml` group. Their filenames matter as much as their contents — Spatie orders
`database/settings` and `database/migrations` together, globally, by filename:

1. **Split** — dated *before* the package's `2023_01_01_000000_create_saml_settings`. Renames the
   application's own fields out of `saml` into the new group, and parks the values the package is
   about to claim (`provision_users`, `sync_groups`, `groups_claim`, `default_uuid`) in a temporary
   group bound to no settings class. Every operation guarded with `$this->migrator->exists()`, so on
   a fresh database the whole thing is a no-op.
2. **Create** — dated after. Adds the application's own fields with `addIfMissing()`-style guards,
   falling back to whatever the project's pre-Spatie settings mechanism held.
3. **Restore** — dated after the package's, and after (2). Writes the parked values over the
   package's defaults and deletes the carriers.

The parking step in (1) is the one that gets left out. The package's migration will happily create
`saml.provision_users` with a default of `false`, and without somewhere to hold the real value
across that create, a migrated site comes up with automatic provisioning quietly switched off.

#### 2. `saml_assertions` already exists, and it is not the same table

This is the one that looks like a non-event and is not. Both sibling projects have:

```php
$table->string('request_id')->nullable();     // nullable, and NOT unique
$table->timestamp('not_on_or_after');         // NOT NULL
```

The package's is:

```php
$table->string('request_id')->unique();       // the unique index IS the replay protection
$table->timestamp('not_on_or_after')->nullable()->index();
```

Two differences, both of which matter:

- **No unique index means no replay protection.** The package relies on the insert failing, not on
  an existence check, precisely because two responses replayed concurrently both pass a check.
- **`not_on_or_after` is NOT NULL there and nullable here.** The package writes null when the
  toolkit cannot report an expiry, which on that schema is an insert that throws — i.e. a refused
  sign-in for anybody whose assertion has no readable expiry.

**Resolution: drop the table from a migration dated before the package's.** Transforming it in place
and marking the package's migration as already run is what this document used to say, and it does not
work — there is no supported way to mark a migration as run, and the ordering makes it impossible
anyway. Laravel's migrator reads the list of already-run migrations **once, at the start of a run**,
then executes everything else in filename order across every registered path. The package's
`2020_01_01_000100_create_saml_assertions_table` therefore runs before any transform a 2025- or
2026-dated project migration could contain, hits the existing table, and the whole run dies on
`SQLSTATE[42S01]: Base table or view already exists`. Nothing a later migration does can help,
because a later migration is precisely what never gets to run.

What works is to sort the drop *before* the package's create:

```
database/migrations/2019_01_01_000000_drop_local_saml_assertions_table.php
```

```php
public function up(): void
{
    Schema::dropIfExists('saml_assertions');
}

public function down(): void
{
    // Nothing. The package's own migration owns this table now.
}
```

The date is deliberate and worth a comment in the file saying so, because it looks like a mistake.
Dropping rather than transforming is right for the reason this document already gave — the rows are
expired assertion IDs and are worth nothing — and it means the table that ends up in place is the
package's own, index and nullability included, rather than a hand-transformed approximation of it.

**Then guard the project's original create migration**, which has already run in production but
which a fresh checkout will run again, before the 2019-dated drop:

```php
public function up(): void
{
    // The package owns this table now. On an existing database this migration
    // has already run and the 2019-dated drop clears up after it; on a fresh
    // one, the package's version is the one that should be created.
    if (Schema::hasTable('saml_assertions')) {
        return;
    }

    // ... original body, now unreachable on a fresh build ...
}
```

Which is a specific case of the general rule:

> **Every already-run migration that creates something the package now owns must be emptied, not
> deleted.** Deleting it does not remove its row from the `migrations` table, so an existing database
> is unaffected either way — but a fresh checkout of the same commit has to produce the same schema
> as an upgraded one, and it will not if a project migration is still creating a table, a settings
> group or a file the package also creates.

In the first migration that applied to three files, and the third is the interesting one:

- the project's `create_saml_settings` settings migration — superseded by the package's;
- a `migrate_saml_settings` data migration that moved values into that group;
- a `store_saml_cert_if_exists` migration that **seeded the certificate files on disk**.

That last one is a trap that connects directly to the storage-format warning below. It writes the old
stripped-armour certificate and key into the very directory the package reads, so a fresh build comes
up with a certificate pair the package then correctly reports as unusable. Deleting the
config-load-time read from `config/saml2.php` is necessary and not sufficient.

#### 3. Attribute and group mappings live in tables, not settings

Both projects keep them in `saml_attribute_mappings` and `saml_group_mappings`. The package keeps
them as settings fields.

**Resolution.** A one-off migration reading those rows into `email_attribute`, `name_attribute` and
`group_role_map`. **This is lossy if the project maps more than those three attributes** — hence the
audit item above. Drop the tables only once the settings screen shows the right values.

#### 4. The SP certificate is read at config-load time

`config/saml2.php` reads `certs/sp.crt`, `certs/sp_new.crt` and `certs/sp.key` off the filesystem as
the config file is evaluated, with a separate path for the testing environment. The package resolves
all three at runtime instead, which is what lets the disk be configurable and the suite fake it.
Both mechanisms active at once is a silent disagreement — see
[the certificate section](#the-service-providers-signing-certificate) below.

**The application's own settings-backed SP metadata needs somewhere to be injected, and it is not
`boot()`.** Organisation name, URL and contact people stay the application's (see the README), and
they have to reach `config('saml2')` before the toolkit is built. Setting them in a provider's
`boot()` puts a settings query on every request in the application, including the overwhelming
majority that have nothing to do with SAML. Defer until the builder is actually being resolved:

```php
$this->app->afterResolving(OneLoginBuilder::class, function () {
    $settings = $this->app->make(SamlSpSettings::class);

    config([
        'saml2.organization' => [...],
        'saml2.contactPerson' => [...],
    ]);
});
```

The vendor package resolves the builder and then calls `bootstrap()` on it, which is where
`config('saml2')` is read — so this runs in between. Without it the settings screen is decorative,
and an administrator changing the support contact watches the published metadata keep naming the old
one.

#### 5. The old middleware and listeners

`App\Http\Middleware\NBCSSAML`, `App\Listeners\SSOLoginLinstener`, `App\Listeners\SSOLogoutLinstener`
are replaced by `RequireSamlAuthentication` and the package's listeners, which the service provider
registers for you. Delete them, and check every route group that referenced the middleware by name —
a route left pointing at a deleted alias is a 500 on a page nobody tests.

#### 6. `config/saml2.php` already exists, and `vendor:publish` will not touch it

**The single most expensive thing in the migration to get wrong.** A project on the earlier
generation of this implementation has a published `config/saml2.php`, and it says:

```php
'routesMiddleware' => ['web'],
```

The package substitutes its own `saml.session` group only where that value is `null` or `[]`, which
is correct — an application that has chosen its own middleware should keep it. So the migrating
project silently keeps `['web']`, the assertion consumer runs behind `PreventRequestForgery`, and
**every sign-in ends at a 419 page on `/saml2/{uuid}/acs`**, having authenticated nobody. The 419
says the page expired; it does not say the identity provider had no CSRF token to send and could not
have had one.

**Resolution.** Open the file and set `routesMiddleware` to `[]`. Do it at the point you publish
rather than at the end of the window.

Where the value is one you set deliberately, the package now wraps it in a middleware of its own that
turns that 419 into an exception naming the setting — so on a current version this fails with an
explanation rather than a mystery. It still fails.

**A health check for this cannot be written as a request test.** Laravel skips CSRF verification
while running tests, so a POST to the assertion consumer succeeds whatever the route is configured to
do. Assert against the route's gathered middleware, and assert **by inheritance from
`Illuminate\Foundation\Http\Middleware\PreventRequestForgery`**, not by class name: that class has
been renamed twice (`VerifyCsrfToken`, then `ValidateCsrfToken`, both of which survive as
subclasses), and an assertion naming one of the aliases passes against a route that does verify a
token. That is not hypothetical — the first version of that check reported the bug as fixed while it
was not.

### The service provider's signing certificate

**This is no longer missing.** It was deferred out of v1.0 with three options on the table; option
one — absorb it into the package — is what happened, once the sibling implementation was actually in
front of us. `SamlSigningCertController`, `saml2:new-cert` and `saml2:cert-swap` all have package
equivalents now. See the README for how the feature works; what follows is only what changes when
you migrate onto it.

**The storage format changed, and it had to.** The old implementation strips the PEM armour and
stores bare base64 — including for the private key. The toolkit's `Utils::formatPrivateKey()`
decides between PKCS#8 and PKCS#1 by looking for the armour, and given a headerless body it assumes
PKCS#1 and wraps it as an RSA private key whatever the bytes actually are. `openssl_pkey_export()`
emits PKCS#8. So the stored key is a PKCS#8 body inside PKCS#1 armour, which nothing can read. The
reason nobody noticed is that those sites have `authnRequestsSigned` set but have never successfully
signed anything with that key. Either re-armour both files by hand, or — much easier — regenerate.

**Delete the config-load-time read.** The top of the old `config/saml2.php` does
`file_get_contents(storage_path(...))` with an `env('APP_ENV') === 'testing'` branch on the path.
The package injects the certificate at runtime in the builder instead. Leave the old block in place
and the two disagree silently on a cached config, with the environment value winning.

**Delete the old code and repoint its routes:** `App\Console\Commands\CreateCertificate`,
`App\Console\Commands\SwapCertificates`,
`App\Http\Controllers\Settings\Saml\SamlSigningCertController`,
`App\Http\Requests\CreateSamlSpCertificateRequest`, and
`resources/views/settings/system/saml/signing-certificate.blade.php`. The `settings.system.saml.*`
route names those used need repointing at the published `SamlCertificateController`.

**Generate a fresh rollover certificate before the first promotion.** The old scheme shares one
`certs/sp.key` between both certificates; the package gives each its own. A migrated site therefore
has an `sp_new.crt` with no `sp_new.key`, which `promote()` correctly refuses.

**Both signing switches arrive off, and this is the one item in the whole migration that silently
changes behaviour.** A site whose `config/saml2.php` had `authnRequestsSigned => true` will stop
signing the moment it moves onto the package. If its identity provider requires signed requests,
switch `sign_requests` on — on the certificate screen, or in the settings table — as part of the
same window, not afterwards.

#### The certificate directory has two writers

Not migration-specific — Path A hits it on the first generate — but it presents during a migration as
a certificate screen insisting the application has nothing to sign with, minutes after a terminal
generated one successfully.

`SpCertificateStore` writes with `['visibility' => 'private']`, which on a local disk means `0600`
files in a `0700` directory owned by whoever wrote them. That is the right default for a private key
and the wrong outcome for almost every real deployment, because **two accounts use that directory**:
the web server user, and the account that runs `artisan` and the cron.

Generate the first certificate with `php artisan saml:generate-certificate --primary` and on a
conventional deployment the files land owned by the deploying account with no group access. The web
server can then neither read the key — so the screen's report is *true* from where it is standing —
nor write a new one. Nothing about this is visible from a terminal, because the account looking is
the account that owns the files.

The fix has two halves, and both are needed:

```php
// config/filesystems.php — otherwise every regenerate clamps the mode back
'local' => [
    // ...
    'permissions' => [
        'file' => ['public' => 0644, 'private' => 0660],
        'dir' => ['public' => 0755, 'private' => 0770],
    ],
],
```

```bash
# Group ownership, with setgid so new files inherit it
chgrp -R www-data storage/app/private/certs
chmod 2770 storage/app/private/certs
```

There is a related trap for anyone writing their own diagnostics around this. Laravel's default
skeleton ships the `local` disk with `'throw' => false`, so a permission failure comes back from
`put()` as a `false` return rather than an exception. A writability check built as `put()` inside a
`try`/`catch` passes on a directory it cannot write to. Read the return value.

### Two decisions to make deliberately, not by default

**Deleting a decommissioned provider is not tidying, it is revocation.** A row in `saml2_tenants`
answers at its own `/saml2/{uuid}/acs` whether or not `saml.default_uuid` points at it, so a provider
left in the table after it stopped being used still grants access. Delete it, or switch it off with
`saml2_tenants.enabled` if it is being kept as a standby.

**Linking by email is a trust decision about the identity provider's `mail` attribute.** An assertion
that matches no NameID falls back to matching an existing account by email address, which is what
connects a hand-created account to single sign-on on its owner's first sign-in. It also means the
address in the assertion is accepted as proof of ownership of that account. Before you carry the
behaviour over, answer two questions about the tenant: does it admit B2B guests, and can a user edit
their own `mail`? Where the answer to either is yes, set `config('saml.user.link_domains')` to the
domains you actually own. Where the application signs external people in on purpose, leave it empty
and read `saml_account_links`.

### Step by step

Do the audit first. Back up. Schedule the window.

**1. Require the package and publish, knowing what publishing will not do.**

Publish the vendor package's config first (its routes are registered from `config/saml2.php` before
its own defaults are merged), then `saml-config`, `saml-migrations` and `saml-admin`.

Then **open `config/saml2.php` and set `routesMiddleware` to `[]`**, because publishing did not touch
the file you already had. See collision 6. Do this now rather than at the end; it is the one setting
that makes the difference between a sign-in and a 419, and it is easiest to get right while you are
already looking at the file.

Do not publish the users-table migration if the columns exist under other names — set
`config/saml.php` to match instead.

**2. Split the settings class, in three migrations.** Per collision 1. This is the longest single
piece of work in the migration and the one most worth testing against a restored copy of production,
because the failure mode is not an error — it is a site that comes up with the wrong values.

**3. Deal with `saml_assertions` by dropping it, from a migration dated before the package's.** Per
collision 2.

**4. Import the attribute and group mappings.** Per collision 3. The audit will have told you whether
this is lossy; if the project maps more attributes than the package's settings hold, this is where an
application-owned resolver becomes necessary rather than optional — see step 6.

**5. Empty every already-run migration that creates something the package now owns.** Per the general
rule in collision 2. Leave the files in place with a docblock explaining why they are empty, and check
specifically for one that seeds certificate files.

**6. Delete the old implementation and repoint everything that named it.** Middleware, listeners,
policies, certificate commands, controllers, request classes, models, enums, views. Two things that
are easy to miss:

- Route groups referencing the old middleware by its alias. A route left pointing at a deleted alias
  is a 500 on a page nobody tests.
- Any application code reading the old settings class. It will not fail at boot; it will fail on the
  first request that touches it, with a Spatie exception about missing properties that names the
  class and not the caller.

If the application's users have more columns worth reading out of an assertion than the package's
default resolver writes, this is where to bind an application-owned `ResolvesSamlUsers`. Extending
`EloquentUserResolver` rather than implementing the contract from scratch is worth doing
deliberately: the matching order — NameID first, then email — is a security decision the package has
already made, and it is not the adopting application's to re-make by accident.

**7. Certificate.** Regenerate rather than re-armouring; the old pair really is unusable. Then, in
this order:

1. `php artisan saml:generate-certificate --primary`
2. Fix the directory ownership per
   [the certificate directory](#the-certificate-directory-has-two-writers) above
3. Remove the old `SAML2_SP_CERT_*` values from `.env` — a certificate on disk takes precedence, so
   leaving them set means the `.env` says one thing and the running application does another
4. Import the application's metadata at the identity provider
5. **Only then** switch `sign_requests` on

**8. Publish and restyle the admin stubs, wire the routes — and keep the flashes.** The controllers
report only by flashing `status`, `error` and `warnings`; the shipped views render all three at the
top of the page, and whatever the restyle does has to keep them. Drop them and a failed action
redirects to a page identical to the one that was submitted, which reads as a button that does
nothing rather than as a failure.

Route names are the ones the views use, and the full list is in the package's `tests/TestCase.php`.
Check the count against your routes file rather than trusting a summary; there are more of them than
there look to be.

Your base controller needs `AuthorizesRequests` — see Path A step 6.

**9. Migrate, then sign in through the real identity provider by hand, and watch the audit log.**

Not a formality, and worth being specific about what "by hand" has to cover, because a successful
first sign-in does not exercise most of what can be broken:

- A user who already had an account, to prove linking works and that `saml_account_links` records it
- A user who did not, if provisioning is on
- The local password form, to prove an account provisioned with `password = null` is refused there
  rather than being offered a password reset
- The certificate screen, with a generate, to prove the directory permissions are right
- Whatever else in the application signs people in — a PIN, a kiosk, an API token

**10. Write down what is deliberately deferred.** Two things in the first migration were left for a
later window on purpose: dropping the now-unreferenced mapping tables, which waits until the settings
screens have been checked against production data, and retiring the identity provider's "My Apps"
tile, which `strict_request_binding` refuses by design. Both are the kind of thing that looks like an
oversight six months later if nobody recorded the reasoning.
