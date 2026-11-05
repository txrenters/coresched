<?php

namespace Database\Factories;

use App\Models\PageVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageVisit>
 */
class PageVisitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'path' => fake()->randomElement(['/', '/pricing', '/termsofservice', '/privacy']),
            'referrer_host' => fake()->optional()->randomElement(['google.com', 'linkedin.com', 'bing.com']),
            'visitor_hash' => hash('sha256', fake()->uuid()),
            'device' => fake()->randomElement(['desktop', 'mobile', 'tablet']),
            'created_at' => now(),
        ];
    }
}
