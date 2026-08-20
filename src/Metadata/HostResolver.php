<?php

namespace NBCSIT\Sso\Metadata;

/**
 * DNS, behind a seam.
 *
 * {@see MetadataFetcher} has to know where a metadata URL actually points
 * before it fetches it, and a test suite cannot depend on the resolver a build
 * machine happens to have. So the one call to the network's name service lives
 * here, alone, and is swapped out in tests.
 */
class HostResolver
{
    /**
     * Every address a host name answers with, or the address itself when the
     * "host" is already a literal.
     *
     * An empty list means the name did not resolve, which the caller treats as
     * a refusal rather than as permission: a URL nothing answers for cannot be
     * checked, so it cannot be trusted.
     *
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Silenced and normalised in one step: a name that does not exist is a
        // `false` from one resolver and an empty array from the next, and both
        // mean the same thing here.
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        $addresses = array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            is_array($records) ? $records : [],
        );

        return array_values(array_filter($addresses, is_string(...)));
    }
}
