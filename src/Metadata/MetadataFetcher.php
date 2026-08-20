<?php

namespace NBCSIT\Sso\Metadata;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use NBCSIT\Sso\Support\MetadataUnreadable;

/**
 * Fetches a metadata document from the URL a provider publishes it at.
 *
 * The toolkit ships `IdPMetadataParser::parseRemoteXML`, which is a bare
 * `file_get_contents` with no timeout and no size limit — on a scheduled run
 * that is a job that hangs until the queue kills it. This goes through the HTTP
 * client instead, which gives a timeout, a retry, and a response that can be
 * faked in a test.
 *
 * It is also the one place in this package that fetches an address somebody
 * typed into an admin screen, unattended, on a schedule. That makes it the
 * package's server-side request forgery surface, so the address is checked
 * before the request and redirects away from it are refused rather than
 * followed.
 */
class MetadataFetcher
{
    /** Generous for a slow IdP, short enough that a daily run cannot stall. */
    private const TIMEOUT_SECONDS = 15;

    /** Separate from the read timeout: an unroutable address should fail fast. */
    private const CONNECT_TIMEOUT_SECONDS = 5;

    /**
     * Metadata is kilobytes. Anything of this size is a wrong URL — an error
     * page, a download — and reading it into memory helps nobody.
     */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** Read granularity for the cap above. */
    private const CHUNK_BYTES = 64 * 1024;

    public function __construct(
        private readonly HostResolver $resolver,
    ) {}

    /**
     * @throws MetadataUnreadable
     */
    public function fetch(string $url): string
    {
        $this->refuseUnroutableHost($url);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->retry(2, 500, throw: false)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'application/samlmetadata+xml, application/xml, text/xml'])
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new MetadataUnreadable('Could not reach '.$url.'. '.$exception->getMessage());
        }

        // Before the status check, because a redirect is not a failure to the
        // HTTP client — it is a 3xx that `successful()` would report as an
        // unhelpful "answered 302".
        if ($response->redirect()) {
            throw new MetadataUnreadable(
                'The metadata URL redirects to '.($response->header('Location') ?: 'somewhere else')
                .'. Store that address instead: following a redirect would mean taking signing certificates from '
                .'wherever it happens to point, including somewhere that is not the identity provider.',
            );
        }

        if (! $response->successful()) {
            throw new MetadataUnreadable('The metadata URL answered '.$response->status().'. Check the address is the provider\'s federation metadata and not a sign-in page.');
        }

        return $this->readCapped($response);
    }

    /**
     * Refuse an address that points inside the network this application runs
     * in.
     *
     * The URL is administrator-supplied and fetched unattended, so without this
     * the daily run is a request generator pointed at whatever the web node can
     * reach — a cloud metadata endpoint, an internal admin interface, anything
     * on the loopback. Resolving the name here rather than trusting how it
     * looks is the point: `internal.example` is a private address wearing a
     * public name.
     *
     * A name that does not resolve is refused too. It cannot be checked, and a
     * URL nothing answers for is a wrong URL either way.
     *
     * This leaves a narrow window in which a name resolves publicly here and
     * privately when the client resolves it again a moment later. Closing it
     * completely means pinning the connection to the address checked, which the
     * HTTP client cannot express portably; the window is seconds wide and needs
     * control of the provider's DNS.
     *
     * @throws MetadataUnreadable
     */
    private function refuseUnroutableHost(string $url): void
    {
        if (config()->boolean('saml.refresh.allow_private_hosts', false)) {
            return;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new MetadataUnreadable('"'.$url.'" is not a URL with a host in it.');
        }

        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new MetadataUnreadable('"'.$host.'" does not resolve to any address.');
        }

        foreach ($addresses as $address) {
            $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);

            if ($public === false) {
                throw new MetadataUnreadable(
                    '"'.$host.'" resolves to '.$address.', which is inside this network. Metadata is fetched '
                    .'unattended, so only addresses on the public internet are accepted. Set '
                    .'`saml.refresh.allow_private_hosts` if the identity provider really is internal.',
                );
            }
        }
    }

    /**
     * Read the body, stopping at the cap.
     *
     * `strlen($response->body())` was the check before, which measured a
     * document the client had already pulled into memory in full — the limit
     * limited nothing. Reading the stream means a response advertising four
     * kilobytes and sending four gigabytes stops at the cap.
     *
     * @throws MetadataUnreadable
     */
    private function readCapped(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();

        // A live response arrives at position zero, but the client may have
        // been handed the same body twice — a retry, or a faked response reused
        // across calls — and a stream read to its end returns nothing the
        // second time.
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof() && strlen($body) <= self::MAX_BYTES) {
            $body .= $stream->read(self::CHUNK_BYTES);
        }

        if (strlen($body) > self::MAX_BYTES) {
            throw new MetadataUnreadable('The metadata URL returned more than '.(self::MAX_BYTES / 1024 / 1024).' MB, which is not a metadata document.');
        }

        return $body;
    }
}
