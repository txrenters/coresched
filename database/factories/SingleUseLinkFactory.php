<?php

namespace Database\Factories;

use App\Models\EventType;
use App\Models\SingleUseLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SingleUseLink>
 */
class SingleUseLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_type_id' => EventType::factory(),
            'team_id' => fn (array $attributes) => EventType::find($attributes['event_type_id'])->team_id,
            'created_by' => null,
            'booking_id' => null,
            'used_at' => null,
        ];
    }

    /**
     * Mark the link as already used.
     */
    public function used(): static
    {
        return $this->state(fn (array $attributes) => [
            'used_at' => now(),
        ]);
    }
}
