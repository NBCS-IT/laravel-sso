<?php

use Illuminate\Support\Carbon;
use NBCSIT\Sso\Support\SamlIdentity;

function identity(array $attributes = []): SamlIdentity
{
    return new SamlIdentity('name-id-1', $attributes);
}

it('reads the first value of an attribute', function () {
    expect(identity(['email' => ['a@example.com', 'b@example.com']])->attribute('email'))
        ->toBe('a@example.com');
});

it('trims the value', function () {
    expect(identity(['email' => ["  a@example.com \n"]])->attribute('email'))->toBe('a@example.com');
});

it('returns null for an attribute the idp did not send', function () {
    expect(identity()->attribute('email'))->toBeNull();
    expect(identity(['email' => []])->attribute('email'))->toBeNull();
    expect(identity(['email' => 'not-an-array'])->attribute('email'))->toBeNull();
});

it('returns null when no attribute name is configured', function () {
    expect(identity(['email' => ['a@example.com']])->attribute(null))->toBeNull();
    expect(identity(['email' => ['a@example.com']])->attribute(''))->toBeNull();
});

it('returns null for a non-scalar value', function () {
    expect(identity(['email' => [['nested']]])->attribute('email'))->toBeNull();
});

it('reads every value of a multi-valued attribute', function () {
    expect(identity(['groups' => ['a', 'b', 'c']])->attributeValues('groups'))->toBe(['a', 'b', 'c']);
});

it('returns an empty list when there are no groups', function () {
    expect(identity()->attributeValues('groups'))->toBe([]);
    expect(identity(['groups' => 'nope'])->attributeValues('groups'))->toBe([]);
    expect(identity(['groups' => ['a']])->attributeValues(null))->toBe([]);
    expect(identity(['groups' => ['a']])->attributeValues(''))->toBe([]);
});

it('drops non-scalar group values and re-indexes', function () {
    expect(identity(['groups' => ['a', ['nested'], 'b']])->attributeValues('groups'))->toBe(['a', 'b']);
});

it('carries the replay-protection fields', function () {
    $expiry = Carbon::parse('2026-01-01 00:00:00');
    $identity = new SamlIdentity('nid', [], '_message-1', $expiry, '_assertion-1');

    expect($identity->messageId)->toBe('_message-1')
        ->and($identity->notOnOrAfter)->toBe($expiry)
        ->and($identity->assertionId)->toBe('_assertion-1')
        ->and($identity->nameId)->toBe('nid');
});

/*
| The assertion ID is inside the signed region and the <samlp:Response>
| envelope's own ID is not, so the assertion ID is the only one of the two an
| attacker cannot change at will.
*/
describe('the replay key', function () {
    it('prefers the assertion id', function () {
        expect((new SamlIdentity('nid', [], '_message-1', null, '_assertion-1'))->replayKey())
            ->toBe('_assertion-1');
    });

    it('falls back to the message id', function () {
        expect((new SamlIdentity('nid', [], '_message-1'))->replayKey())->toBe('_message-1')
            ->and((new SamlIdentity('nid', [], '_message-1', null, ''))->replayKey())->toBe('_message-1');
    });

    it('is null when there is nothing to key on', function () {
        expect((new SamlIdentity('nid', []))->replayKey())->toBeNull()
            ->and((new SamlIdentity('nid', [], '', null, ''))->replayKey())->toBeNull();
    });
});
