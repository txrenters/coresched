<?php

namespace Database\Factories;

use App\Enums\EventTypeKind;
use App\Enums\LocationType;
use App\Models\EventType;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventType>
 */
class EventTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().' '.fake()->word();

        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'kind' => EventTypeKind::OneOnOne,
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'duration_minutes' => 30,
            'rolling_days' => 60,
            'location_type' => LocationType::CustomLink,
            'location_detail' => 'https://example.com/meet',
            'is_active' => true,
        ];
    }

    /**
     * Attach the event type to an existing owner and their current team.
     */
    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $owner->id,
            'team_id' => $owner->current_team_id,
        ]);
    }

    /**
     * Make bookings wait for a host's approval.
     */
    public function requiresConfirmation(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_confirmation' => true,
        ]);
    }

    /**
     * Make the event type a round robin across a pool of hosts.
     */
    public function roundRobin(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => EventTypeKind::RoundRobin,
        ]);
    }

    /**
     * Make the event type require every host to attend.
     */
    public function collective(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => EventTypeKind::Collective,
        ]);
    }

    /**
     * Make the event type a group event with the given number of seats.
     */
    public function group(int $seats = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => EventTypeKind::Group,
            'seats_per_slot' => $seats,
        ]);
    }
}
