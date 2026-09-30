<?php

namespace App\Models;

use App\Enums\WebhookScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A key one of our own applications uses to reach the API.
 *
 * The full key is shown once, when it is made, and never stored: the table
 * keeps a hash and a short prefix. The prefix is only an index — it says which
 * row to check, and the hash says whether the caller actually holds the key.
 *
 * A key may also name a webhook endpoint: the organization's bookings are then
 * reported there — only those from the caller's own links, or every one, as
 * its scope says — signed with a secret of the key's own. The secret is
 * encrypted, not hashed, because every delivery has to sign with it.
 *
 * @property int $id
 * @property string $name
 * @property ?int $team_id
 * @property string $prefix
 * @property string $token_hash
 * @property ?string $webhook_url
 * @property ?string $webhook_secret
 * @property WebhookScope $webhook_scope
 * @property ?int $created_by
 * @property ?Carbon $last_used_at
 * @property ?Carbon $revoked_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[Fillable(['name', 'team_id', 'prefix', 'token_hash', 'created_by'])]
#[Hidden(['token_hash', 'webhook_secret'])]
class ApiKey extends Model
{
    /** Characters before the separator, used to find the row. */
    public const PREFIX_LENGTH = 12;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'webhook_scope' => 'referenced',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'webhook_secret' => 'encrypted',
            'webhook_scope' => WebhookScope::class,
        ];
    }

    /**
     * The organization this key speaks for, or null for a system-wide key.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Make a key, returning the model and the one and only copy of the secret.
     *
     * A key with no organization speaks for all of them, which is why only a
     * super admin can issue one.
     *
     * @return array{key: self, token: string}
     */
    public static function issue(string $name, ?User $creator = null, ?Team $team = null): array
    {
        $prefix = Str::lower(Str::random(self::PREFIX_LENGTH));
        $secret = Str::random(48);

        $key = self::create([
            'name' => $name,
            'team_id' => $team?->id,
            'prefix' => $prefix,
            'token_hash' => Hash::make($secret),
            'created_by' => $creator?->id,
        ]);

        return ['key' => $key, 'token' => $prefix.'.'.$secret];
    }

    /**
     * The key a token belongs to, or null when it matches nothing usable.
     *
     * A revoked key is not found at all, so revoking is immediate rather than
     * something the caller can keep using until a cache lapses.
     */
    public static function findByToken(string $token): ?self
    {
        [$prefix, $secret] = array_pad(explode('.', $token, 2), 2, null);

        if (blank($prefix) || blank($secret)) {
            return null;
        }

        $key = self::query()->active()->where('prefix', $prefix)->first();

        return $key !== null && Hash::check($secret, $key->token_hash) ? $key : null;
    }

    /**
     * Scope to keys that have not been revoked.
     *
     * @param  Builder<ApiKey>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * Whether this key may read a booking page.
     *
     * A system-wide key may read any. An organization's key may read that
     * organization's page, and the pages of people who belong to it —
     * nothing else, or one organization's admin could read another's.
     */
    public function canRead(User|Team $page): bool
    {
        if ($this->team_id === null) {
            return true;
        }

        return $page instanceof Team
            ? $page->id === $this->team_id
            : $page->teams()->whereKey($this->team_id)->exists();
    }

    /**
     * Point the key's webhooks at a URL, or stop them with null.
     *
     * Returns the signing secret when one was made, which is the only time it
     * is shown: a key gets one when it first gains an endpoint, and a new one
     * only when asked to rotate. Changing the URL alone keeps the secret, so
     * the receiver does not have to be reconfigured for a move.
     */
    public function pointWebhookAt(?string $url, bool $rotateSecret = false, WebhookScope $scope = WebhookScope::Referenced): ?string
    {
        if ($url === null) {
            $this->forceFill([
                'webhook_url' => null,
                'webhook_secret' => null,
                'webhook_scope' => WebhookScope::Referenced,
            ])->save();

            return null;
        }

        $secret = $this->webhook_secret === null || $rotateSecret ? Str::random(48) : null;

        $this->forceFill(array_filter([
            'webhook_url' => $url,
            'webhook_secret' => $secret,
            'webhook_scope' => $scope,
        ]))->save();

        return $secret;
    }

    /**
     * Scope to the keys a booking of this organization is reported to.
     *
     * Only the organization's own keys: a system-wide key reads every page,
     * but hearing about every organization's bookings is not something it
     * asked for by existing.
     *
     * @param  Builder<ApiKey>  $query
     */
    public function scopeReceivingWebhooksFor(Builder $query, int $teamId): void
    {
        $query->active()->where('team_id', $teamId)->whereNotNull('webhook_url');
    }

    /**
     * Whether this key's webhook should hear about a booking right now.
     */
    public function receivesWebhookFor(Booking $booking): bool
    {
        return ! $this->isRevoked()
            && $this->webhook_url !== null
            && $this->team_id === $booking->team_id
            && $this->webhook_scope->covers($booking->reference);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
