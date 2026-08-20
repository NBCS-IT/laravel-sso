<?php

namespace NBCSIT\Sso;

use NBCSIT\Saml2\Auth as Saml2Auth;
use NBCSIT\Saml2\OneLoginBuilder;
use NBCSIT\Sso\Certificates\SpCertificateStore;
use NBCSIT\Sso\Models\IdentityProvider;
use NBCSIT\Sso\Settings\SamlSettings;
use OneLogin\Saml2\Auth as OneLoginAuth;
use OneLogin\Saml2\Utils as OneLoginUtils;

/**
 * The package's builder, taught about key rollover.
 *
 * An identity provider rolling its signing key publishes the outgoing and the
 * incoming certificate together, sometimes for weeks, and signs with either one
 * during that window. The package hands the toolkit a single `x509cert` — the
 * one column its table has — so during a rollover roughly half of all sign-ins
 * fail signature validation, and switching column to the other certificate just
 * moves which half.
 *
 * The toolkit itself has handled this for years through `x509certMulti`, which
 * it tries in turn. All this class does is pass it. `x509cert` is left in place
 * alongside for any code path that reads only that.
 *
 * Bound over `OneLoginBuilder` in {@see SsoServiceProvider}; the
 * vendor package's `ResolveTenant` middleware asks the container for the parent
 * class by name and gets this.
 */
class MultiCertificateOneLoginBuilder extends OneLoginBuilder
{
    /**
     * Mirrors the parent, with the certificate list added. Overridden whole
     * because the parent builds the config inside the closure it registers and
     * offers nothing smaller to hook.
     */
    public function bootstrap(): void
    {
        if (config()->boolean('saml2.proxyVars', false)) {
            OneLoginUtils::setProxyVars(true);
        }

        $this->app->singleton('OneLogin_Saml2_Auth', function () {
            $config = config()->array('saml2');

            $this->setConfigDefaultValues($config);

            $config['idp'] = [
                'entityId' => $this->tenant->idp_entity_id,
                'singleSignOnService' => ['url' => $this->tenant->idp_login_url],
                'singleLogoutService' => ['url' => $this->tenant->idp_logout_url],
                'x509cert' => $this->tenant->idp_x509_cert,
            ];

            $certificates = $this->signingCertificates();

            if (count($certificates) > 1) {
                $config['idp']['x509certMulti'] = ['signing' => $certificates];
            }

            $config['sp']['NameIDFormat'] = $this->resolveNameIdFormatPrefix($this->tenant->name_id_format);

            $config = $this->applySpCertificate($config);
            $config = $this->applySigningPolicy($config);
            $config = $this->applySecurityFloor($config);

            return new OneLoginAuth($config);
        });

        $this->app->singleton(
            Saml2Auth::class,
            fn () => new Saml2Auth($this->app->make('OneLogin_Saml2_Auth'), $this->tenant),
        );
    }

    /**
     * The service provider's own certificate, as it is on disk right now.
     *
     * Only when both files are there: a certificate without its key cannot sign
     * and cannot decrypt, and half of a pair is worse than none of it. When
     * there is nothing on disk, whatever `config/saml2.php` supplied from the
     * environment survives untouched — an application already configured that
     * way keeps working, and adopting this feature is a matter of generating a
     * certificate rather than of upgrading.
     *
     * `x509certNew` is a key the vendor config has never had. Setting it here is
     * enough: the toolkit takes the whole `sp` array as given, and reads that
     * key in exactly one place — the metadata document — where it publishes the
     * rollover certificate as a second signing key. It never signs with it and
     * never decrypts with it, so offering it costs nothing.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applySpCertificate(array $config): array
    {
        $store = $this->app->make(SpCertificateStore::class);

        $certificate = $store->primaryCertificate();
        $key = $store->primaryKey();

        if ($certificate !== null && $key !== null) {
            $config['sp']['x509cert'] = $certificate;
            $config['sp']['privateKey'] = $key;
        }

        $secondary = $store->secondaryCertificate();

        if ($secondary !== null) {
            $config['sp']['x509certNew'] = $secondary;
        }

        return $config;
    }

    /**
     * The administrator's signing switches, and the guard that stops them
     * taking the site down.
     *
     * The toolkit does not treat "sign requests, but there is no key" as a
     * request it cannot sign. `Settings::checkSPSettings()` treats it as an
     * invalid configuration and the constructor throws — inside the closure
     * above, which is the singleton every SAML route resolves. Sign-in, the
     * assertion consumer, logout *and the metadata document* all stop working
     * together, and the metadata document is the page an administrator would
     * have gone to in order to work out what was wrong.
     *
     * So the setting is a request, not an instruction: with no usable
     * certificate it is ignored here and the screen says so, rather than being
     * honoured into a site nobody can sign into or fix. That state is reachable
     * without anybody being careless — a database restored into a fresh
     * environment, a second web node with no `certs/` directory, a deploy that
     * ran migrations before the disk was mounted.
     *
     * Read from the final `sp` values rather than from the store, so that a
     * certificate configured the old way through the environment counts as
     * usable too.
     *
     * The encryption switches are deliberately left alone: those are demands on
     * the identity provider rather than capabilities of this application, and
     * two of them would trip the same refusal. What this package does demand of
     * the identity provider is in {@see self::applySecurityFloor()}, which runs
     * after this and is not conditional on having a certificate.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applySigningPolicy(array $config): array
    {
        if (($config['sp']['x509cert'] ?? '') === '' || ($config['sp']['privateKey'] ?? '') === '') {
            return $config;
        }

        $settings = $this->app->make(SamlSettings::class);

        if ($settings->sign_requests) {
            $config['security']['authnRequestsSigned'] = true;
            $config['security']['logoutRequestSigned'] = true;
            $config['security']['logoutResponseSigned'] = true;
        }

        if ($settings->sign_metadata) {
            $config['security']['signMetadata'] = true;
        }

        return $config;
    }

    /**
     * Security settings this package will not let an application configure
     * below.
     *
     * Forced here rather than fixed in `config/saml2.php` because that file is
     * published into each consuming application: changing the vendor default
     * would reach new installs only, and would be silently editable afterwards.
     * This runs last, so it is also the final word over `saml2.php`.
     *
     * `wantAssertionsSigned` is the load-bearing one. Without it the toolkit
     * accepts a response whose signature covers the <samlp:Response> envelope
     * rather than the assertion — and every identity claim this package reads,
     * the NameID it matches accounts on and the groups it maps to roles, lives
     * in the assertion. Entra ID signs the assertion by default, so requiring it
     * costs a correctly configured provider nothing and closes the gap between
     * "does sign" and "must sign".
     *
     * `wantMessagesSigned` is deliberately not forced: Entra ID does not sign
     * the envelope unless the enterprise application is switched to "Sign SAML
     * response and assertion", so forcing it would break every existing
     * integration. It is offered as configuration instead.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applySecurityFloor(array $config): array
    {
        $config['strict'] = true;
        $config['security']['wantAssertionsSigned'] = true;
        $config['security']['wantXMLValidation'] = true;
        $config['security']['relaxDestinationValidation'] = false;

        $config['security']['wantMessagesSigned'] = config()->boolean('saml.security.want_messages_signed', false);

        // Follows the binding switch rather than standing alone, and that
        // coupling is load-bearing. The toolkit refuses a response carrying an
        // InResponseTo whenever it was given no request ID to match it against
        // — and until the vendor package binds request IDs, it never is. On its
        // own this setting therefore refuses every ordinary sign-in, because
        // Entra ID answers an AuthnRequest with an InResponseTo. Tied to the
        // switch that turns the binding on, it means what it says: refuse a
        // response nobody asked for.
        $config['security']['rejectUnsolicitedResponsesWithInResponseTo'] =
            config()->boolean('saml.security.reject_unsolicited', false)
            && config()->boolean('saml.security.strict_request_binding', false);

        return $config;
    }

    /**
     * `saml2.tenantModel` resolves tenants as {@see IdentityProvider},
     * so this arrives cast. A provider configured before metadata import existed
     * has none, and one certificate is not a rollover, so both cases come back
     * as a list the caller can count.
     *
     * @return list<string>
     */
    private function signingCertificates(): array
    {
        $stored = $this->tenant->getAttribute('idp_x509_cert_multi');

        return is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];
    }
}
