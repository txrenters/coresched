<?php

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(12),
            'banner_until' => null,
            'recipients_count' => 0,
        ];
    }

    /**
     * Show a banner until the given number of days from now.
     */
    public function withBanner(int $days = 3): static
    {
        return $this->state(fn () => ['banner_until' => now()->addDays($days)]);
    }
}
