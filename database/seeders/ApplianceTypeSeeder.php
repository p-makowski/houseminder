<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ApplianceType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ApplianceTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $types = [
            'Refrigerator',
            'Washing Machine',
            'Dryer',
            'Dishwasher',
            'HVAC / Air Conditioner',
            'Water Heater',
            'Oven / Range',
            'Microwave',
            'Vacuum Cleaner',
            'Car / Vehicle',
            'Lawn Mower',
            'Generator',
            'Other',
        ];

        foreach ($types as $name) {
            ApplianceType::updateOrCreate(
                ['name' => $name, 'household_id' => null],
                []
            );
        }
    }
}
