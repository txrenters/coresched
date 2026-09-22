<?php

namespace App\Models;

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
 * @property int $id
 * @property string $name
 * @property string $prefix
 * @property string $token_hash
 * @property ?int $created_by
 * @property ?Carbon $last_used_at
 * @property ?Carbon $revoked_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
#[Fillable(['name', 'prefix', 'token_hash', 'created_by'])]
#[Hidden(['token_hash'])]
class ApiKey extends Model
{
    /** Characters before the separator, used to find the row. */
    public const PREFIX_LENGTH = 12;

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
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
     * @return array{key: self, token: string}
     */
    public static function issue(string $name, ?User $creator = null): array
    {
        $prefix = Str::lower(Str::random(self::PREFIX_LENGTH));
        $secret = Str::random(48);

        $key = self::create([
            'name' => $name,
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

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
