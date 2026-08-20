<?php

namespace NBCSIT\Sso\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use NBCSIT\Sso\Database\Factories\SamlAssertionFactory;

/**
 * A SAML assertion this application has already accepted.
 *
 * Its only job is to make replay impossible: an assertion arriving with a
 * message ID already in this table is refused.
 *
 * @property int $id
 * @property string $request_id
 * @property Carbon|null $not_on_or_after
 */
class SamlAssertion extends Model
{
    /** @use HasFactory<SamlAssertionFactory> */
    use HasFactory, Prunable;

    protected $fillable = ['request_id', 'not_on_or_after'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'not_on_or_after' => 'datetime',
        ];
    }

    protected static function newFactory(): SamlAssertionFactory
    {
        return SamlAssertionFactory::new();
    }

    /**
     * Once an assertion can no longer be valid, remembering it proves nothing.
     *
     * A response whose SubjectConfirmationData carries no `NotOnOrAfter` lands
     * here with a null expiry, and `NULL < ?` is never true — so those rows
     * would otherwise be kept forever. They fall back to their own age instead,
     * with a week's grace: long enough that no assertion recorded before it
     * could still be in date, short enough that the table does not grow without
     * bound.
     *
     * The whole predicate is nested so that the `chunkById` the pruner wraps it
     * in is ANDed against the pair rather than against the second half of it.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where(function (Builder $query) {
            $query->where('not_on_or_after', '<', now()->subDay())
                ->orWhere(fn (Builder $undated) => $undated
                    ->whereNull('not_on_or_after')
                    ->where('created_at', '<', now()->subDays(7)));
        });
    }
}
