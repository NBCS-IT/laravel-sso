<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use NBCSIT\Sso\Metadata\HostResolver;
use NBCSIT\Sso\Metadata\MetadataFetcher;
use NBCSIT\Sso\Support\MetadataUnreadable;
use NBCSIT\Sso\Tests\Fixtures\FakeHostResolver;

/**
 * @param  array<string, list<string>>  $answers
 */
function fetcher(array $answers = []): MetadataFetcher
{
    app()->instance(HostResolver::class, new FakeHostResolver($answers));

    return app(MetadataFetcher::class);
}

it('returns the document', function () {
    Http::fake(['idp.example.edu.au/*' => Http::response('<xml/>')]);

    expect(fetcher()->fetch('https://idp.example.edu.au/metadata.xml'))->toBe('<xml/>');
});

it('reports a status that is not a success', function () {
    Http::fake(['idp.example.edu.au/*' => Http::response('nope', 404)]);

    fetcher()->fetch('https://idp.example.edu.au/metadata.xml');
})->throws(MetadataUnreadable::class, 'answered 404');

it('reports a host it could not reach', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    fetcher()->fetch('https://idp.example.edu.au/metadata.xml');
})->throws(MetadataUnreadable::class, 'Could not reach');

/*
| Following a redirect means taking signing certificates from wherever it
| happens to point. The stored URL is the one an administrator checked, so a
| redirect is reported for them to act on rather than obeyed.
*/
describe('a redirect', function () {
    it('is refused, and names where it was sent', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response('', 302, ['Location' => 'http://10.0.0.5/metadata.xml'])]);

        fetcher()->fetch('https://idp.example.edu.au/metadata.xml');
    })->throws(MetadataUnreadable::class, 'redirects to http://10.0.0.5/metadata.xml');

    it('is refused even when it names nowhere', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response('', 302)]);

        fetcher()->fetch('https://idp.example.edu.au/metadata.xml');
    })->throws(MetadataUnreadable::class, 'redirects to somewhere else');
});

/*
| The URL is administrator-typed and fetched unattended on a schedule, which is
| the shape of a server-side request forgery. What the name resolves to is what
| matters — a private address can wear a perfectly public-looking name.
*/
describe('the address check', function () {
    it('refuses a name that resolves onto the loopback', function () {
        Http::fake();

        fetcher(['idp.example.edu.au' => ['127.0.0.1']])->fetch('https://idp.example.edu.au/metadata.xml');
    })->throws(MetadataUnreadable::class, 'which is inside this network');

    it('refuses a name that resolves into a private range', function () {
        Http::fake();

        fetcher(['idp.example.edu.au' => ['203.0.113.10', '10.1.2.3']])
            ->fetch('https://idp.example.edu.au/metadata.xml');
    })->throws(MetadataUnreadable::class, 'resolves to 10.1.2.3');

    it('refuses a name that resolves to a unique-local IPv6 address', function () {
        Http::fake();

        fetcher(['idp.example.edu.au' => ['fd00::1']])->fetch('https://idp.example.edu.au/metadata.xml');
    })->throws(MetadataUnreadable::class, 'resolves to fd00::1');

    it('refuses a name that resolves to nothing', function () {
        Http::fake();

        fetcher(['idp.example.edu.au' => []])->fetch('https://idp.example.edu.au/metadata.xml');
    })->throws(MetadataUnreadable::class, 'does not resolve to any address');

    it('refuses something that is not a URL with a host in it', function () {
        Http::fake();

        fetcher()->fetch('/metadata.xml');
    })->throws(MetadataUnreadable::class, 'is not a URL with a host in it');

    it('sends no request at all when it refuses', function () {
        Http::fake();

        try {
            fetcher(['idp.example.edu.au' => ['127.0.0.1']])->fetch('https://idp.example.edu.au/metadata.xml');
        } catch (MetadataUnreadable) {
            // The point of the test is what did not happen.
        }

        Http::assertNothingSent();
    });

    it('stands aside for an application with a genuinely internal provider', function () {
        config(['saml.refresh.allow_private_hosts' => true]);
        Http::fake(['adfs.internal/*' => Http::response('<xml/>')]);

        expect(fetcher(['adfs.internal' => ['10.1.2.3']])->fetch('https://adfs.internal/metadata.xml'))
            ->toBe('<xml/>');
    });
});

/*
| The cap used to be measured against a body the client had already pulled into
| memory in full, so it limited nothing.
*/
describe('the size cap', function () {
    it('refuses a document larger than it', function () {
        Http::fake(['idp.example.edu.au/*' => Http::response(str_repeat('x', 3 * 1024 * 1024))]);

        fetcher()->fetch('https://idp.example.edu.au/metadata.xml');
    })->throws(MetadataUnreadable::class, 'returned more than 2 MB');

    it('reads a document that spans several chunks', function () {
        $document = str_repeat('x', 200 * 1024);
        Http::fake(['idp.example.edu.au/*' => Http::response($document)]);

        expect(fetcher()->fetch('https://idp.example.edu.au/metadata.xml'))->toBe($document);
    });
});

describe('the resolver behind it', function () {
    it('answers an address literal with itself, without asking anybody', function () {
        expect((new HostResolver)->resolve('127.0.0.1'))->toBe(['127.0.0.1'])
            ->and((new HostResolver)->resolve('fd00::1'))->toBe(['fd00::1']);
    });

    it('answers nothing for a name that cannot exist', function () {
        // `.invalid` is reserved by RFC 2606 precisely so that this is safe to
        // ask: it is guaranteed never to resolve, on any network.
        expect((new HostResolver)->resolve('nbcs-sso-test.invalid'))->toBe([]);
    });
});
