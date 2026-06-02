<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(ApplianceTypeSeeder::class);

        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'email_verified_at' => now(),
            ]
        );

        if ($user->households()->doesntExist()) {
            $household = Household::create(['name' => 'Test Household']);
            $user->households()->attach($household->id, ['role' => 'owner']);
        }
    }
}
