<?php

namespace NBCSIT\Sso\Tests\Fixtures;

use NBCSIT\Sso\Metadata\HostResolver;
use NBCSIT\Sso\Tests\TestCase;

/**
 * Name resolution without a network.
 *
 * Bound over the real resolver for the whole suite in {@see TestCase},
 * so no test depends on what a build machine's DNS says about
 * `idp.example.edu.au`. Tests exercising the fetcher's address check construct
 * their own with the answers they want.
 */
class FakeHostResolver extends HostResolver
{
    /**
     * `$answers` maps a host to the addresses it resolves to; anything not
     * named gets `$default`, which is TEST-NET-3 and counts as public.
     *
     * @param  array<string, list<string>>  $answers
     */
    public function __construct(
        private readonly array $answers = [],
        private readonly string $default = '203.0.113.10',
    ) {}

    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? [$this->default];
    }
}
