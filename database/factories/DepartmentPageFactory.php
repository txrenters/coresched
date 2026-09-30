<?php

namespace Database\Factories;

use App\Models\DepartmentPage;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepartmentPage>
 */
class DepartmentPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->word().' departments',
            'description' => fake()->sentence(),
        ];
    }
}
