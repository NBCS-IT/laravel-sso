<?php

namespace NBCSIT\Sso\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use NBCSIT\Sso\Database\Factories\SamlMetadataEventFactory;
use NBCSIT\Sso\Enums\SamlMetadataOutcome;
use NBCSIT\Sso\Enums\SamlMetadataSource;
use NBCSIT\Sso\Support\MetadataChange;

/**
 * One entry in an identity provider's history.
 *
 * Written when something happened — a provider was added, values changed, a
 * change was held back, a fetch failed. A scheduled check that found the
 * metadata unchanged writes nothing; the provider's `metadata_checked_at` is
 * the record that it ran.
 *
 * `provider_name` and `metadata_url` are copied in rather than read through the
 * relation, because the question this table answers is usually asked after
 * somebody deleted the provider and sign-in stopped working.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $provider_name
 * @property SamlMetadataSource $source
 * @property SamlMetadataOutcome $outcome
 * @property string|null $metadata_url
 * @property string $message
 * @property array<int, array<string, mixed>> $change_set
 * @property list<string> $warnings
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property-read IdentityProvider|null $provider
 * @property-read Authenticatable|Model|null $user
 */
class SamlMetadataEvent extends Model
{
    /** @use HasFactory<SamlMetadataEventFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'provider_name',
        'source',
        'outcome',
        'metadata_url',
        'message',
        'change_set',
        'warnings',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => SamlMetadataSource::class,
            'outcome' => SamlMetadataOutcome::class,
            'change_set' => 'array',
            'warnings' => 'array',
        ];
    }

    protected static function newFactory(): SamlMetadataEventFactory
    {
        return SamlMetadataEventFactory::new();
    }

    /**
     * @return BelongsTo<IdentityProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(IdentityProvider::class, 'tenant_id');
    }

    /**
     * The consuming application's user model, named in `config('saml.user.model')`
     * rather than hard-coded — this package has no `App\Models\User` to point at.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config('saml.user.model');

        return $this->belongsTo($model);
    }

    /**
     * The column is `change_set` and not the obvious `changes` because Eloquent
     * already has a protected `$changes` property — the dirty-attribute list.
     * A column of that name reads as an attribute from outside the model and as
     * an empty array from inside it, which is a bug that looks like nothing.
     *
     * @return list<MetadataChange>
     */
    public function changeList(): array
    {
        return array_map(MetadataChange::fromArray(...), array_values($this->change_set));
    }

    /**
     * Who did this. Nobody is the answer for a scheduled run, and that is the
     * answer worth printing — an unattended change is the one to look at first.
     */
    public function actor(): string
    {
        $name = $this->user?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : 'Scheduled check';
    }
}
