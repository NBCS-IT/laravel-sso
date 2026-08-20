<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use Illuminate\Http\UploadedFile;

/**
 * SAML metadata documents to read in tests.
 *
 * The certificates are real, self-signed and long-lived, because half of what
 * is under test is what this application can tell an administrator about a
 * certificate — its thumbprint, when it expires — and none of that works on a
 * made-up string.
 */
final class SamlMetadataFixtures
{
    /** CN=idp.example.edu.au, expires 9 August 2036. */
    public const CERT_A = 'MIIDGzCCAgOgAwIBAgIUAWZlAv1rotShwBzONSA56GGGG4gwDQYJKoZIhvcNAQELBQAwHTEbMBkGA1UEAwwSaWRwLmV4YW1wbGUuZWR1LmF1MB4XDTI2MDgxMjA3MjYyM1oXDTM2MDgwOTA3MjYyM1owHTEbMBkGA1UEAwwSaWRwLmV4YW1wbGUuZWR1LmF1MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtzw3kGVKK/6fKuHm1cUnsDlWhrAfhoiFTkVt0VX3JqXzTdmT+4IFnYT+2JvbQONTXH2BIaLWXZ5h983BYgTZRJI4RzJwkaAhEBpctGqberomLI52RDDDgt3C0f5dm1LQIGOHxLwV4fB35cXJnz0NoAOkYMJYxMBLDm4kuPm2eq5IcPvw763HRxJt9uapLNFEizDVGAAwFuzJxcwOeeu74Qj1FKPbZpU2Q6hptjqSeDnYQ9IAp1p+PQAqeP8wL1N6vnKCscd2TT0ypblyee+9TFxURzr00fvu3b3XuxpaclBjcAksdS4esnAZnpi/0PbH+GiZmapcVThkbaUnY0NtBQIDAQABo1MwUTAdBgNVHQ4EFgQU/mX3uUAejxZbpRhjbabMc85Coo0wHwYDVR0jBBgwFoAU/mX3uUAejxZbpRhjbabMc85Coo0wDwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQsFAAOCAQEABK75GbFAeeH+kgiGZjeEBBLm/MhFO0Dq4tc8s+Kmz4AMf4fJkEO9HyWWqVflAN92Z+9nX3mqcXDjUuX+53NyJnojHg+xn0QSUSzuFnaPy/I5x+nWoOh8WrGFYzBvq2Y/Euv+qWkY2bpkxidG+BHITSpxJdEQeypOk6B9z/kKe0JD2IeA6PN4LI5iLICeIRFQaOSKy802DB1vUGrliFLAGgE0FDs0nqcTbgW6eYw221EzroFdULsl20LtvcrdJxrPUiHPtJq2CKldlzBxYUFnW3ecQg9uuhppiyEBU0modNv6WSup4iPFG37iTOhG8EAEgGb4UeRYTsDKyRRX0QKsPA==';

    /** SHA-1 thumbprint of {@see self::CERT_A}, as an IdP console prints it. */
    public const THUMBPRINT_A = '82E90219C95EE375345DD0B19AA56BABD71BBFF1';

    /** CN=idp-next.example.edu.au, expires 9 August 2036. The rollover key. */
    public const CERT_B = 'MIIDJTCCAg2gAwIBAgIUeOHg5lwOt/DdwAcCU8JqqM76LsIwDQYJKoZIhvcNAQELBQAwIjEgMB4GA1UEAwwXaWRwLW5leHQuZXhhbXBsZS5lZHUuYXUwHhcNMjYwODEyMDcyNjU3WhcNMzYwODA5MDcyNjU3WjAiMSAwHgYDVQQDDBdpZHAtbmV4dC5leGFtcGxlLmVkdS5hdTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBANSjW5Zt4Al7DX5idqbksiDVAeQ/67hDEHRj5SOoPMw4L7HdMMGYsmqBYo5NTeErUssFBPD/iHBCgyJi6uAhWpzXK8eTfmkOdICb3NT+ck7iPrvnMHV6tPUo3h28FhbEjnVYxDUHT4MyExmElEZ7/hJfKEVWtGkHhHkRgg/615yKJOgziBX/bP/i1hBC2c5va5gdiUrt4cIWRFMyMECEnQQh5UOHSyq8PLLDn3gNVKHiMTR/aDvx+qf39Lk4Wea9R9R9BWxFNrIQvU+ee4qmPLnIrVElqRq3V3TBBmY8TIJTS8kb4rTpfHBWkaM1hTxDs3KaHXMtOIqO+obfhJcEPDcCAwEAAaNTMFEwHQYDVR0OBBYEFHAl2+Ske4HlySHtQ8qmNs5ekvN+MB8GA1UdIwQYMBaAFHAl2+Ske4HlySHtQ8qmNs5ekvN+MA8GA1UdEwEB/wQFMAMBAf8wDQYJKoZIhvcNAQELBQADggEBAJ2u41HP4lPhI5TO34Xaq6oZri14Kb0cDoWgm+ZNASzHD5XYalARE5tnKE4w03f/dJVczcX9KBr1iDhditXXt85qIq5z0ZSxBP+qPVM5A+HyLAx9toBzfyfAhN/IsSugTL8znyioB0hy2vHjrz4tbt1ZrpXOcVJHF9LNUfugZR9yKb1+TUz9b3QX8Gf7jugOss7B3VnnAKLNpds5llrskGaztGgql0Qd6CAW/R0pnfMj1u22aLM8bddXDJJn36Z7xOplqIZ/aFqEIxYQr/XGnARI9I0o1RUhTjpFV1fod8XJlpMr4BDpFZvJzz8afoAFJnbJ4lclqrgJs3mNOTHRKVg=';

    public const ENTITY_ID = 'https://sts.example.edu.au/adfs/services/trust';

    public const SSO_URL = 'https://sts.example.edu.au/adfs/ls/';

    public const SLO_URL = 'https://sts.example.edu.au/adfs/ls/logout';

    public const REDIRECT_BINDING = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect';

    public const POST_BINDING = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST';

    /**
     * @param  list<string>  $certificates
     */
    public static function document(
        string $entityId = self::ENTITY_ID,
        string $ssoUrl = self::SSO_URL,
        ?string $sloUrl = self::SLO_URL,
        array $certificates = [self::CERT_A],
        ?string $nameIdFormat = 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        ?string $validUntil = null,
        string $ssoBinding = self::REDIRECT_BINDING,
    ): string {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .self::entity($entityId, $ssoUrl, $sloUrl, $certificates, $nameIdFormat, $validUntil, $ssoBinding);
    }

    /**
     * Several providers in one file, the way a federation publishes them.
     *
     * @param  list<string>  $entityIds
     */
    public static function federation(array $entityIds): string
    {
        $entities = array_map(
            fn (string $entityId) => self::entity(
                $entityId,
                $entityId.'/sso',
                $entityId.'/slo',
                [self::CERT_A],
                null,
                null,
                self::REDIRECT_BINDING,
            ),
            $entityIds,
        );

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<EntitiesDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata">'
            .implode('', $entities)
            .'</EntitiesDescriptor>';
    }

    /**
     * A service provider's metadata — this application's own, for instance.
     * Valid SAML, and no use at all for configuring an identity provider.
     */
    public static function serviceProviderDocument(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="https://map.example.edu.au/saml2/metadata">'
            .'<SPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            .'<AssertionConsumerService Binding="'.self::POST_BINDING.'" Location="https://map.example.edu.au/saml2/acs" index="0"/>'
            .'</SPSSODescriptor>'
            .'</EntityDescriptor>';
    }

    public static function upload(string $xml, string $name = 'metadata.xml'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $xml);
    }

    /**
     * @param  list<string>  $certificates
     */
    private static function entity(
        string $entityId,
        string $ssoUrl,
        ?string $sloUrl,
        array $certificates,
        ?string $nameIdFormat,
        ?string $validUntil,
        string $ssoBinding,
    ): string {
        $keys = implode('', array_map(
            fn (string $certificate) => '<KeyDescriptor use="signing">'
                .'<ds:KeyInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#">'
                .'<ds:X509Data><ds:X509Certificate>'.$certificate.'</ds:X509Certificate></ds:X509Data>'
                .'</ds:KeyInfo></KeyDescriptor>',
            $certificates,
        ));

        return '<EntityDescriptor xmlns="urn:oasis:names:tc:SAML:2.0:metadata" entityID="'.$entityId.'"'
            .($validUntil === null ? '' : ' validUntil="'.$validUntil.'"').'>'
            .'<IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            .$keys
            .($sloUrl === null ? '' : '<SingleLogoutService Binding="'.self::REDIRECT_BINDING.'" Location="'.$sloUrl.'"/>')
            .($nameIdFormat === null ? '' : '<NameIDFormat>'.$nameIdFormat.'</NameIDFormat>')
            .'<SingleSignOnService Binding="'.$ssoBinding.'" Location="'.$ssoUrl.'"/>'
            .'</IDPSSODescriptor>'
            .'</EntityDescriptor>';
    }
}
