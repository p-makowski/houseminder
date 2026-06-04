<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Appliance;
use App\Models\ApplianceType;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appliance>
 */
class ApplianceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'appliance_type_id' => ApplianceType::factory(),
            'name' => fake()->words(2, true),
            'model' => strtoupper(fake()->bothify('??###')),
            'purchase_date' => null,
            'is_plan_confirmed' => false,
        ];
    }
}
