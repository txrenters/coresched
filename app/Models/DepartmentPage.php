<?php

namespace App\Models;

use Database\Factories\DepartmentPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A named public page listing a chosen set of an organization's teams.
 *
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team $team
 * @property-read Collection<int, Group> $groups
 */
#[Fillable(['team_id', 'name', 'slug', 'description'])]
class DepartmentPage extends Model
{
    /** @use HasFactory<DepartmentPageFactory> */
    use HasFactory;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        /*
         * The link is a random code rather than the name, so a page can carry
         * a sentence-long title and be renamed without breaking links already
         * handed out.
         */
        static::creating(function (DepartmentPage $page) {
            if (empty($page->slug)) {
                $page->slug = static::generateUniqueSlug($page->team_id);
            }
        });
    }

    /**
     * Get the organization the page belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the teams listed on the page.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'department_page_groups')
            ->withTimestamps();
    }

    /**
     * Build a random lowercase code that is unique inside the organization.
     */
    public static function generateUniqueSlug(int $teamId): string
    {
        do {
            $slug = Str::lower(Str::random(8));
        } while (static::query()->where('team_id', $teamId)->where('slug', $slug)->exists());

        return $slug;
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
