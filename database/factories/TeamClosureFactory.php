<?php

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamClosure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamClosure>
 */
class TeamClosureFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $day = fake()->dateTimeBetween('+1 week', '+2 months')->format('Y-m-d');

        return [
            'team_id' => Team::factory(),
            'name' => 'Company outing',
            'starts_on' => $day,
            'ends_on' => $day,
        ];
    }
}
