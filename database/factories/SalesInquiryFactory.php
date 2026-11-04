<?php

namespace Database\Factories;

use App\Enums\SalesInquiryStatus;
use App\Http\Requests\Marketing\ContactSalesRequest;
use App\Models\SalesInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesInquiry>
 */
class SalesInquiryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'company' => fake()->company(),
            'team_size' => fake()->randomElement(ContactSalesRequest::TEAM_SIZES),
            'plan' => fake()->optional()->randomElement(['team', 'business', 'enterprise']),
            'message' => fake()->optional()->sentence(),
            'status' => SalesInquiryStatus::New,
        ];
    }

    /**
     * Indicate that someone has already reached out.
     */
    public function contacted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SalesInquiryStatus::Contacted,
        ]);
    }
}
