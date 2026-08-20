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
2. `php artisan vendor:publish --provider="NBCSIT\Saml2\ServiceProvider"` — the vendor package
   registers its routes from `config/saml2.php` before it merges its own defaults, so that file has
   to exist.
3. `php artisan vendor:publish --tag=saml-config`.
4. `php artisan vendor:publish --tag=saml-migrations` — adds `saml_name_id` to `users`. If you want
   the deactivated-account refusal or a sign-in timestamp, add those columns here too and name them
   in `config/saml.php`.
5. `php artisan vendor:publish --tag=saml-admin` — the three controllers and three views.
6. Wire routes for the published controllers, under the names the views use
   (`admin.settings.saml.*`; the full list is in this package's `tests/TestCase.php`).
7. Apply the `saml.auth` middleware where you want it. Nothing to do about CSRF or sessions on
   `saml2/*` — leave `saml2.routesMiddleware` empty and the package's own `saml.session` group
   covers both; see the README.
8. Implement the local-login refusal for accounts with `password = null`. This is not optional — see
   the README.
9. `php artisan migrate`.
10. Open the settings screen, add the identity provider from its metadata URL, choose it, and switch
    single sign-on on.
11. If the identity provider wants signed requests, run `php artisan saml:generate-certificate
    --primary`, give it this application's metadata, and only then switch signing on. In that order:
    signing before it has the certificate makes it reject everything.

---

## Path B — replacing an existing SAML implementation

> **Status: not yet written from experience.** What follows is the pre-flight audit and the
> collisions that are known to exist, gathered by reading the sibling projects. The step-by-step
> half of this path is deliberately absent until somebody has actually done it once — a guide
> written from imagination for a migration this fiddly is worse than no guide. Whoever migrates the
> first project writes it, correcting everything below that turns out to be wrong.

### Who this applies to

An application on an **earlier generation of this implementation** — the ancestor this package was
rewritten from — does not lack a SAML implementation. It will already declare
`nbcsit/laravel-saml2`, `laravel/framework: ^13.0`, `spatie/laravel-permission` and
`spatie/laravel-settings`, so the package's *requirements* are already met. That is the easy part.

### Pre-flight audit

Do all of this and write the answers down before changing a line.

- [ ] **Inventory the existing `App\Settings\SamlSettings`** — every field and its current value in
      each environment. You are about to split this class in two.
- [ ] **Dump `saml_attribute_mappings` and `saml_group_mappings`.** Check that nothing in them falls
      outside what the package's three settings fields can hold: `email_attribute`, `name_attribute`
      and `group_role_map`. **A project that maps more attributes than those three loses the extra
      ones**, and this is the audit that catches it before rather than after.
- [ ] **Diff the existing `saml_assertions` schema against the package's.** See below — they are not
      the same, and the difference is load-bearing.
- [ ] **List every route and middleware group that names `App\Http\Middleware\NBCSSAML`.**
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
class); the package's eight fields are dropped from it. Write a Spatie settings migration that
renames the surviving rows into the new group. Do not skip this by leaving the old class in place.

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
  Marking the package's migration as already run on a table without that index leaves the
  application looking protected and not being protected.
- **`not_on_or_after` is NOT NULL there and nullable here.** The package writes null when the
  toolkit cannot report an expiry, which on that schema is an insert that throws — i.e. a refused
  sign-in for anybody whose assertion has no readable expiry.

**Resolution.** Write a per-project transform migration: delete rows with a null or duplicate
`request_id`, add the unique index, and make `not_on_or_after` nullable. Then mark the package's own
`create_saml_assertions_table` migration as already run. Old rows are worth nothing — they are
expired assertion IDs — so deleting the table's contents first is a legitimate simplification, and
much the safer one.

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

#### 5. The old middleware and listeners

`App\Http\Middleware\NBCSSAML`, `App\Listeners\SSOLoginLinstener`, `App\Listeners\SSOLogoutLinstener`
are replaced by `RequireSamlAuthentication` and the package's listeners, which the service provider
registers for you. Delete them, and check every route group that referenced the middleware by name —
a route left pointing at a deleted alias is a 500 on a page nobody tests.

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

### Rough order of work

Once the audit is done, the shape is:

1. Back up. Schedule the window.
2. Require the package; publish config; do **not** publish the users-table migration if the columns
   already exist (check what they are called and set `config/saml.php` to match).
3. Split the settings class and write the settings migration that renames the rows.
4. Write the `saml_assertions` transform.
5. Write the attribute/group mapping import.
6. Delete the old middleware, listeners, policies and services; repoint routes.
7. Move the SP certificate and key onto the configured disk in PEM form, or regenerate; delete the
   config-load-time read from `config/saml2.php`; set the two signing toggles to match what the old
   config said.
8. Publish the admin stubs, restyle them, wire the routes.
9. Migrate. Sign in through the real identity provider, by hand, and watch the audit log.

Step 9 is not optional and not a formality.
