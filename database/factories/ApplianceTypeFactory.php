<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApplianceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplianceType>
 */
class ApplianceTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'household_id' => null,
        ];
    }
}
