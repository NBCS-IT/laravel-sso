# nbcsit/laravel-sso

SAML single sign-on for NBCS Laravel applications: identity provider metadata import, certificate
rollover handling, replay protection, group-to-role synchronisation, an audit log, and publishable
admin screens for all of it.

It sits on top of [`nbcsit/laravel-saml2`](https://github.com/NBCS-IT/laravel-saml2), which speaks
the protocol. This package is everything above that — the part that keeps a provider's configuration
current and turns an assertion into a signed-in local user.

---

## What it is for

Several applications here authenticate against the same identity provider (Microsoft Entra ID). The
code that does it had grown well past what the vendor package does, and keeping five copies of it
means five places to patch when a signature-validation bug or a certificate-rollover edge case turns
up.

**The valuable part is the metadata and certificate handling.** That is where a bug is silent and
expensive: half of all sign-ins failing during a key rollover, or an unattended refresh quietly
repointing the entity ID at somewhere new.

---

## Requirements

| | |
|---|---|
| PHP | 8.3+ |
| Laravel | 13.x |
| `nbcsit/laravel-saml2` | ^2.4 |
| `spatie/laravel-permission` | ^6.18 — **required**, not optional |
| `spatie/laravel-settings` | ^3.4 — **required**, not optional |
| `ext-openssl` | Reads identity provider certificates, and mints this application's own |

Both Spatie packages are hard requirements. That is a deliberate choice: it removes an entire layer
of abstraction that would have had exactly one implementation in every application here.

## Installation

Both this package and the vendor SAML package are private, so both need a repository entry:

```json
"repositories": [
    { "name": "saml2", "type": "vcs", "url": "https://github.com/NBCS-IT/laravel-saml2" },
    { "name": "sso",   "type": "vcs", "url": "https://github.com/NBCS-IT/laravel-sso" }
]
```

```bash
composer require nbcsit/laravel-sso

php artisan vendor:publish --tag=saml-config      # config/saml.php
php artisan vendor:publish --tag=saml-migrations  # the users-table columns (skip if you have them)
php artisan vendor:publish --tag=saml-admin       # the three controllers and three Blade views

php artisan vendor:publish --provider="NBCSIT\Saml2\ServiceProvider"   # config/saml2.php
php artisan migrate
```

`config/saml2.php` is the vendor package's, and you do need it published: that package registers its
own routes from that config before it merges its defaults, so an unpublished copy leaves the routes
half-configured. This package overrides `saml2.tenantModel` and `saml2.routesMiddleware` from its
service provider and leaves the rest of the file alone.

**If your application already has a SAML implementation, stop here and read
[`docs/adoption.md`](docs/adoption.md) instead.** Adoption is a data migration, not an install.

### Two things the application still does itself

The package ships no web routes and touches no `bootstrap/app.php`. What is left to you:

1. **Apply the middleware.** The alias `saml.auth` is registered for you; where it goes is your
   decision.

   ```php
   Route::middleware('saml.auth')->group(function () { /* … */ });
   ```

2. **Name routes for the published admin screens.** The views reference
   `admin.settings.saml.*` — see `tests/TestCase.php` in this repository for the full list, which is
   the set the shipped views expect. That list now includes four
   `admin.settings.saml.certificate.*` routes; the link to that screen is wrapped in a
   `Route::has()` check, so an application that has not named them yet keeps working rather than
   500ing on the page that links to it.

### What the vendor package's own routes get, without you asking

Sign-in is a write to the session, so `saml2/*/acs` needs one — and the vendor package ships
`routesMiddleware => []`, which means an integration that follows its README to the letter gets a
sessionless assertion consumer. That failure is invisible at the point it happens: the sign-in
succeeds, the session goes out with the response, and the next guarded page redirects back to the
identity provider. Forever.

So this package registers a middleware group, `saml.session`, and points `saml2.routesMiddleware` at
it when you have left that empty:

```php
EncryptCookies, AddQueuedCookiesToResponse, StartSession, ShareErrorsFromSession, SubstituteBindings
```

`VerifyCsrfToken` is **deliberately not in it** — the identity provider posts to the ACS endpoint
cross-site, so there is no token to check. That is also why there is no CSRF exemption to write in
`bootstrap/app.php`: these routes never go through `web`. Replay protection for those posts is this
package's, and it is per-assertion rather than per-form.

Set `saml2.routesMiddleware` yourself and this package leaves it alone — but whatever you set had
better start a session, because `HandleSamlSignIn` throws rather than authenticating a user into a
session that is about to be discarded.

---

## The one thing you must not skip

**A provisioned account is created with `password = null`, and your application is responsible for
refusing to let it sign in locally.**

The package creates accounts with no password so that an SSO-only account cannot be used at a
password form. Nothing here can enforce that at your login route — you have to:

```php
public function canLoginLocally(): bool
{
    return $this->password !== null;
}
```

Without it, `password = null` is not "SSO only". Depending on your login code it can be "an account
with an empty password", which is the opposite of what was intended.

---

## Configuration

`config/saml.php`, in full:

| Key | Default | What it does |
|---|---|---|
| `user.model` | `App\Models\User` | The application's user model |
| `user.columns.name_id` | `saml_name_id` | Where the SAML NameID is stored. **Required** |
| `user.columns.email` | `email` | |
| `user.columns.name` | `name` | |
| `user.columns.active` | `null` | Naming a column switches on the deactivated-account refusal |
| `user.columns.last_login_at` | `null` | Naming a column switches on the sign-in-time write |
| `guard` | `web` | The guard roles are granted under |
| `fallback_login_route` | `login` | Where a guest goes when SSO is not configured |
| `gate` | `manage settings` | The ability the published admin screens authorise against |
| `certificate.disk` | `local` | Where this application's own certificate and key are kept |
| `certificate.path` | `certs` | The directory on that disk |
| `certificate.days` | `3650` | How long a generated certificate is valid for |
| `certificate.bits` | `2048` | RSA key size |
| `certificate.subject.*` | `null` | The generated certificate's distinguished name |
| `refresh.enabled` | `true` | Whether the daily metadata refresh is scheduled at all |
| `refresh.schedule` | `03:15` | When it runs |

**The certificate disk must not be web-served.** A private key on a disk with a `url` configured is
a private key anybody can download; the package refuses to write to one, but the right answer is not
to point it there. Add the directory to your `.gitignore`, and note that `local` means
`storage/app/private`, which is **per node** — see [the certificate section](#the-service-providers-own-certificate).

`active` and `last_login_at` default to `null` because most applications here have neither column.
With `active` unset, `SamlLoginOutcome::Inactive` cannot happen — every account that exists may sign
in. Both configurations are covered by the test suite.

Everything an administrator owns — whether SSO is on, whether unknown users are provisioned, the
attribute names, the group-to-role map, which provider is in use — lives in Spatie Settings under
the group `saml`, on the published settings screen.

### Settings this package deliberately does not own

Your **service provider metadata** — `org_name`, `org_url`, `contact_name`, `contact_email`,
`support_contact_name`, `support_contact_email` — is not the package's. Those values are read by the
vendor package's `config/saml2.php` to build the metadata document this site publishes, not by
anything here. Keep them in your own settings class.

They are called out because in the application this package was extracted from they used to sit in
the same settings class as everything above, which makes them look like package concerns. Deleting
them breaks your SP metadata endpoint, and nothing will tell you until an administrator on the other
end tries to re-import it.

---

## What it does

### Identity provider metadata

A provider is set up from the metadata document the identity provider publishes, either uploaded or
fetched from a URL, rather than by copying four values and a page of base64 out of somebody else's
console. The reader adds what the toolkit's parser leaves out and an administrator needs: the
document's `validUntil` date, a count of how many providers a federation file describes, the NameID
format in the short form the tenant column wants, and warnings rather than refusals wherever a
document is usable but odd.

### Certificate rollover

An identity provider rolling its signing key publishes the outgoing and the incoming certificate
together, sometimes for weeks, and signs with either one during that window. The vendor package
stores a single certificate, so during a rollover roughly half of all sign-ins fail signature
validation — and switching to the other certificate just moves which half.

This package stores every published certificate and hands the whole list to the toolkit, which has
supported exactly this for years. Nothing about a rollover should be visible to anybody.

### The service provider's own certificate

The other half of the same problem. The certificate above is the identity provider's; this one is
the application's own, and the difference that matters is that nothing fetches it — somebody imports
it at the identity provider by hand. So it cannot be replaced in place without an outage, and the
whole design follows from that.

```bash
php artisan saml:generate-certificate               # the rollover certificate. Changes nothing yet
php artisan saml:promote-certificate                # once the identity provider has it
php artisan saml:generate-certificate --primary     # first-time setup; refuses to overwrite silently
```

The rollover is:

1. **Generate a rollover certificate.** It is written beside the one in use, with its own fresh
   private key. Nothing signs with it, and nothing changes.
2. **Re-import this application's metadata at the identity provider.** Both certificates are
   published in it as signing keys, so the identity provider now trusts either.
3. **Promote.** The rollover certificate becomes the one this application signs with, and the one it
   replaced becomes the rollover certificate — still published, so the identity provider keeps
   accepting it until the next one is generated.

Promotion is therefore its own undo: run it twice and the disk is back where it started. It also
writes `sp_previous.crt` and `sp_previous.key` before it touches anything, which is the copy to
restore from if a write fails half way through. That is one generation of history, overwritten each
time — not an archive, and not a backup.

Each certificate gets its **own** private key. Rotating a certificate while keeping the key is not
rotation; it is a new wrapper around the same secret.

RSA-2048, self-signed, ten years, `sha256`. The subject comes from `saml.certificate.subject`, and
anything left null is worked out from `APP_URL` and `APP_NAME`.

**Precedence.** `SAML2_SP_CERT_x509` and `SAML2_SP_CERT_PRIVATEKEY` keep working exactly as before
while the disk is empty, so upgrading changes nothing until you generate something. Once the disk
has a certificate, **the disk wins.** A site that leaves those environment variables set and then
presses Generate has quietly switched certificates; the settings screen shows what is actually in
force.

**On more than one web node**, `local` gives each node its own `certs/` directory. Generate on one
and the others sign with something else — which fails for about half of all sign-ins and looks
nothing like a certificate problem. Point `saml.certificate.disk` at shared storage, or bake the
files into the image.

### Signing what this application sends

Two switches on the certificate screen, both off by default:

- **Sign the messages this application sends** — authentication requests and logout messages alike.
  One switch rather than three, because an identity provider that wants a signed `AuthnRequest`
  wants signed logout messages too.
- **Sign the metadata document this application publishes** — some identity providers ask for this;
  most do not.

Both are **ignored at runtime while there is no usable certificate**, and that is not defensiveness.
The toolkit treats "sign, but there is no key" as an invalid configuration and throws while building
its settings object — which every SAML route resolves, the metadata endpoint included. Honouring the
switch would take sign-in, logout *and* the page you would have gone to in order to diagnose it down
together, with no way back in. So the switch is a request rather than an instruction: the settings
screen refuses to save it, and the builder ignores it if it is somehow set anyway.

A certificate is "usable" when both files are present, both parse, and the key actually signed the
certificate. That last check is the one the implementation this replaced did not have, and a
mismatched pair produces signatures no identity provider can verify while looking entirely fine.

### Guarded changes

A fetched document may roll keys. It may not move goalposts.

Signing certificates change on a schedule the identity provider owns, and keeping up with them
unattended is the entire reason a refresh URL exists. The **entity ID, the endpoints and the NameID
format** say *who* is being trusted and *how a person is identified*. A change to one of those is
recorded on the provider as pending and waits for an administrator to apply or discard it.

Rolling a key means the outgoing and incoming certificates overlap for a while. A document that
shares **no** certificate at all with what is configured is not rolling a key — it is naming a new
set of keys as the ones this application will accept signatures from, which is the whole of
authentication. Those wait too, with the incoming certificates held in `pending_certificates` until
somebody applies them.

### The audit log

`saml_metadata_events` records a creation, a change, a held change, a discard or a failure, with who
did it — nobody, for a scheduled run, which is the answer worth printing. A run that changed nothing
writes no row; `metadata_checked_at` on the provider records that it ran. Three hundred "no change"
rows a year would bury the one worth reading.

### Replay protection

An accepted assertion's **assertion ID** is recorded in `saml_assertions` and refused on sight if it
comes back. The unique index is what enforces it, not an existence check — two responses replayed
concurrently both pass a check, but only one insert survives.

The assertion ID rather than the `<samlp:Response>` envelope's ID, because Entra ID signs the
assertion and not the envelope by default. An unsigned envelope can be rebuilt around an unexpired
signed assertion under a fresh ID, so keying on it lets the same assertion in twice. The envelope's
ID is still used as a fallback where the toolkit reports no assertion ID.

A response carrying neither is refused, since there is then nothing to key replay detection on.
`config('saml.security.allow_unkeyed_assertions')` turns that into a warning in the log and a
sign-in, for an identity provider that emits malformed responses and cannot be fixed.

Rows past their expiry are pruned daily, at `config('saml.prune.schedule')` and switchable off with
`config('saml.prune.enabled')`. The prune is scoped to this package's model — an unscoped
`model:prune` would prune your application's models too, which is not this package's decision. By
hand:

```bash
php artisan model:prune --model="NBCSIT\Sso\Models\SamlAssertion"
```

### Account linking

An assertion is matched on NameID first and, failing that, on email address — which is how an
account somebody created by hand gets connected to single sign-on the first time its owner signs in.

**That fallback is a trust decision about the identity provider's `mail` attribute, not a
convenience.** It treats the address in the assertion as proof of ownership of whatever local
account already holds it. Where the tenant admits B2B guests, or lets a user edit their own mail
attribute, it is a way to capture an account — a privileged one included.
`config('saml.user.link_domains')` limits which domains may do it; empty means any, which is right
only for an application that signs external people in on purpose.

Every such claim is written to `saml_account_links` and logged at notice level. Nothing renders that
table; it is there so a takeover is visible afterwards.

### Group-to-role synchronisation

The map is IdP group value to local role name. **Only roles named in the map are added or removed.**
A role granted by hand and not in the map — Super Admin, say, which no identity provider group
should confer — is left alone rather than being stripped on the user's next login.

### The scheduled refresh

`saml:refresh-metadata` re-reads every provider that has a metadata URL and automatic refresh on. It
is scheduled daily by the service provider, at the time `config('saml.refresh.schedule')` names, and
`config('saml.refresh.enabled')` switches it off. A provider added from an uploaded file has no URL
and is therefore never touched unattended.

```bash
php artisan saml:refresh-metadata
php artisan saml:refresh-metadata --provider="Entra ID"
php artisan saml:refresh-metadata --force        # including providers with auto-refresh off
```

It exits non-zero if any provider failed, so a cron that reports only on failure still reports.

Two refusals apply to the unattended path specifically, because a warning nobody reads is not a
control:

- **A metadata URL that is not `https://` is refused,** not warned about. The document names the key
  whose signature this application accepts; over plain HTTP whoever is on the path chooses who may
  sign in.
- **The URL's host is resolved before the request, and private, loopback, link-local and
  unique-local addresses are refused.** The URL is administrator-typed and fetched daily by a job, so
  without this the schedule is a request generator pointed at anything the web node can reach.
  Redirects are reported rather than followed, for the same reason.
  `config('saml.refresh.allow_private_hosts')` lifts the address check for a genuinely internal
  identity provider — an ADFS server — and lifts it for every provider.

### Switching a provider off

`saml.enabled` is a real kill switch: with it off, the assertion consumer refuses to sign anybody in,
not merely the middleware stops sending them to the identity provider.

Per provider, `saml2_tenants.enabled` does the same for one row. `saml.default_uuid` only chooses
which provider `/login` hands off to — every row is reachable at its own `/saml2/{uuid}/acs` and is a
live trust anchor until it is switched off or deleted. A standby provider kept for failover should be
switched off until it is needed.

---

## Overriding what it does

### The user model

`NBCSIT\Sso\Contracts\ResolvesSamlUsers` is the whole surface between this package and your users:
find, provision, isActive, sync. The default implementation reads column names from config and
covers every application this was written for. If you need something else — a user store that is not
Eloquent, a provisioning rule of your own — bind your own:

```php
$this->app->bind(ResolvesSamlUsers::class, MyUserResolver::class);
```

The package binds its default with `bindIf`, so yours wins regardless of provider order.

**The matching order is not configurable and should not become so.** NameID first, because it is the
identity the provider guarantees and it survives a change of surname; email second, so an account
created by hand connects to single sign-on the first time its owner signs in. `sync()` will not take
an email address another account already holds.

### Group synchronisation

`NBCSIT\Sso\Groups\GroupSynchroniser` is a concrete class resolved from the container. Rebind it if
you need different behaviour. There is no interface, because with Spatie Permission as a hard
requirement it would have had exactly one implementation.

### The admin screens

They are published, not served. The views use one application's Blade components, layout, Tailwind
palette and route names, none of which is portable — and abstracting that would produce a theming
layer larger than the views. A published controller is also an editable controller: when one project
needs an extra confirmation step, it edits its copy rather than this package growing a hook.

The certificate screen is the concrete case that argument was written for. Replacing the
certificate in use has no undo, so its form is behind a collapsed disclosure, asks for the word
`replace` to be typed, and validates that server-side — three layers, of which only the last one
actually holds. A project that wants a different gesture edits its copy.

`SamlMetadataOutcome::badgeClasses()` returns Tailwind classes. It is kept because it is genuinely
useful and harmless to a consumer that ignores it, but it is Tailwind-flavoured and that is worth
knowing before you wire it into something else.

---

## Security

### What this package forces, and what it leaves to you

`MultiCertificateOneLoginBuilder` puts a floor under whatever `config/saml2.php` says, because that
file is published into each application and a value fixed there reaches new installs only. Forced,
not negotiable:

- `strict` — on.
- `wantAssertionsSigned` — on. Without it the toolkit accepts a response whose signature covers the
  envelope rather than the assertion, and every identity claim this package reads lives in the
  assertion. Entra ID signs the assertion by default, so this costs a correct configuration nothing.
- `wantXMLValidation` — on; `relaxDestinationValidation` — off.

Offered as configuration rather than forced, because turning them on unilaterally would break a
working Entra integration:

| `config('saml.security.*')` | What it does |
|---|---|
| `want_messages_signed` | Additionally requires the `<samlp:Response>` envelope to be signed. Switch the enterprise application to "Sign SAML response and assertion" at the IdP **first**. |
| `allow_unkeyed_assertions` | See **Replay protection** above. |

And two that are **on by default**, which you switch off only for a reason:

| `config('saml.security.*')` | What it does |
|---|---|
| `strict_request_binding` | Ties a response to the AuthnRequest this application sent, which is what closes login CSRF — without it the assertion consumer accepts any validly signed, in-date, correctly addressed response, whether or not anybody here asked for it. Carried down to the vendor package as `saml2.strictRequestBinding`. **It refuses IdP-initiated sign-in**, so an application reached through the Entra "My Apps" tile must retire the tile or switch this off. |
| `reject_unsolicited` | Additionally has the toolkit refuse a response carrying an `InResponseTo` it cannot account for. Takes effect only together with the switch above, and deliberately so: on its own it would reject every ordinary sign-in, because Entra answers an AuthnRequest with an `InResponseTo` and there would be no stored request ID to match it against. Together, the two decide what a **lost request ID** means — a dropped cookie, an expired session. On: the sign-in fails and the person retries. Off: the binding silently falls back to accepting any valid response, which is the thing it exists to prevent. |

Left to the application, and not coverable from here:

- **`proxyVars` must stay off** unless the proxy in front of you is known to strip inbound
  `X-Forwarded-*`. php-saml reads those headers directly; it does not consult Laravel's
  `TrustProxies` middleware or any trusted-proxy list, so `TrustProxies` alone does not cover it.
  With it on behind a proxy that passes client headers through, the host php-saml believes it is
  becomes attacker-shaped, and that host shapes both Destination validation and the ACS URL in your
  metadata.
- **Restrict allowed hosts.** Even with `proxyVars` off, php-saml falls back to `$_SERVER['HTTP_HOST']`,
  so Destination validation is only as strong as your host filtering. Use `TrustHosts`, or have the
  web server refuse unknown `Host` values.
- **Your base controller must `use AuthorizesRequests`.** Every published admin action calls
  `$this->authorize(config('saml.gate'))`, and Laravel 12/13's default skeleton controller does not
  include the trait.
- **`password = null` is only "SSO only" if you enforce it** — see above.

### Known limitations

- **SHA-1 and RSA-1.5 remain acceptable signature algorithms.** php-saml 4.3.2 has no setting to
  refuse them, so this cannot be configured away — closing it means patching the vendored toolkit or
  moving off `onelogin/php-saml`. Exploiting it needs a chosen-prefix SHA-1 collision against a
  certificate the identity provider controls, so the practical risk is low. Re-check on each php-saml
  upgrade whether an algorithm allow-list has landed upstream.
- **`SAML2_DEBUG` must not be set in production.** It defaults to `APP_DEBUG`, which is false there;
  set independently it puts validation-failure reasons — which quote parts of the response — into
  the session as `saml2.error_detail` and into the log. Do not render that key on a user-facing
  error page.

---

## What it does not do

- **It does not verify the signature on a metadata document.** This is why the guarded-change design
  exists: a document fetched over HTTP is not evidence enough to rewrite who is being trusted. Do
  not read the guarded-change behaviour as belt and braces on top of signature verification — it is
  instead of it.
- No SP-initiated logout, no IdP-initiated flows, no attribute-based authorisation beyond the group
  map.
- No assertion or NameID encryption. `wantAssertionsEncrypted` and `nameIdEncrypted` are left where
  the vendor config puts them; switching them on needs an encryption key descriptor design of its
  own.
- No scheduled certificate rotation. Promotion is deliberate, because the moment a certificate takes
  effect is the moment the identity provider has to already have it.
- No audit-log rows for certificate actions. `saml_metadata_events` is identity provider history —
  none of its columns fit. The commands' output and the screen's flash messages are the record.
- A metadata URL that is not HTTPS produces a warning, not a refusal.

---

## Development

```bash
composer install
composer test              # Pest, on Testbench
composer test-coverage     # the same, with a 100% gate
composer lint              # Pint, then PHPStan at Larastan level 7
```

The suite runs against `NBCSIT\Sso\Tests\Fixtures\User`, a minimal user model in the harness. That
is deliberate: if a test needs `App\Models\User`, the package has a dependency it should not have.

The suite generates real RSA keypairs rather than hard-coding one, and memoises them, so `composer
test` is a couple of hundred milliseconds slower than it would otherwise be. A private key committed
to a repository is a private key somebody eventually copies.

The published stubs are tested too, loaded exactly as an application would have them and mounted on
routes the harness defines. A published stub nobody tests rots, and the person it rots on is
whoever adopts this package next.

## Licence

MIT.
