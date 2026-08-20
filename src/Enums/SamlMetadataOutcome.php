<?php

namespace NBCSIT\Sso\Enums;

/**
 * Every way reading a metadata document can end.
 *
 * Named cases rather than a boolean because "nothing changed" and "something
 * changed but I would not write it" are the two an administrator most needs to
 * tell apart, and both of them are successes.
 */
enum SamlMetadataOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Held = 'held';
    case Unchanged = 'unchanged';
    case Removed = 'removed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Provider added',
            self::Updated => 'Changes applied',
            self::Held => 'Changes held for review',
            self::Unchanged => 'No change',
            self::Removed => 'Provider removed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Tailwind classes for the badge this outcome gets in the log.
     */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Created, self::Updated => 'bg-emerald-100 text-emerald-800',
            self::Held, self::Removed => 'bg-amber-100 text-amber-900',
            self::Unchanged => 'bg-slate-100 text-slate-700',
            self::Failed => 'bg-rose-100 text-rose-800',
        };
    }

    public function succeeded(): bool
    {
        return $this !== self::Failed;
    }
}
