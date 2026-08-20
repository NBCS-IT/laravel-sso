<?php

use NBCSIT\Sso\Enums\SamlLoginOutcome;
use NBCSIT\Sso\Enums\SamlMetadataOutcome;
use NBCSIT\Sso\Enums\SamlMetadataSource;

/*
| The wording and the badge colours are what an administrator reads at 8am after
| sign-in broke overnight, so every case is exercised rather than only the ones
| the happy path happens to reach.
*/

describe('a sign-in outcome', function () {
    it('succeeds only when it signed somebody in', function () {
        expect(SamlLoginOutcome::SignedIn->succeeded())->toBeTrue();

        foreach (SamlLoginOutcome::cases() as $case) {
            if ($case !== SamlLoginOutcome::SignedIn) {
                expect($case->succeeded())->toBeFalse();
            }
        }
    });

    it('carries wording for the page the middleware renders', function () {
        foreach (SamlLoginOutcome::cases() as $case) {
            expect($case->message())->not->toBeEmpty();
        }

        expect(SamlLoginOutcome::Inactive->message())->toContain('deactivated')
            ->and(SamlLoginOutcome::Replayed->message())->toContain('already been used')
            ->and(SamlLoginOutcome::MissingEmail->message())->toContain('email address')
            ->and(SamlLoginOutcome::NotProvisioned->message())->toContain('do not have an account')
            ->and(SamlLoginOutcome::ProvisioningFailed->message())->toContain('could not be created')
            ->and(SamlLoginOutcome::Unverifiable->message())->toContain('cannot check for reuse')
            ->and(SamlLoginOutcome::Disabled->message())->toContain('switched off')
            ->and(SamlLoginOutcome::TenantDisabled->message())->toContain('no longer in use');
    });
});

describe('a metadata outcome', function () {
    it('fails only when it failed', function () {
        expect(SamlMetadataOutcome::Failed->succeeded())->toBeFalse();

        foreach (SamlMetadataOutcome::cases() as $case) {
            if ($case !== SamlMetadataOutcome::Failed) {
                expect($case->succeeded())->toBeTrue();
            }
        }
    });

    it('labels and colours every case', function () {
        foreach (SamlMetadataOutcome::cases() as $case) {
            expect($case->label())->not->toBeEmpty()
                ->and($case->badgeClasses())->toStartWith('bg-');
        }

        // Tailwind-flavoured, and deliberately so — see the README.
        expect(SamlMetadataOutcome::Held->badgeClasses())
            ->toBe(SamlMetadataOutcome::Removed->badgeClasses())
            ->and(SamlMetadataOutcome::Failed->badgeClasses())->toContain('rose');
    });
});

describe('a metadata source', function () {
    it('labels every case', function () {
        foreach (SamlMetadataSource::cases() as $case) {
            expect($case->label())->not->toBeEmpty();
        }

        expect(SamlMetadataSource::Upload->label())->toBe('Uploaded file');
    });
});
