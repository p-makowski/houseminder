<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appliance_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamps();
            // No unique index on (name, household_id): SQLite treats NULLs as always
            // distinct in UNIQUE constraints, so a composite index would not protect
            // system rows (household_id = null) from duplicates. Idempotency is
            // enforced by ApplianceTypeSeeder::updateOrCreate() instead.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appliance_types');
    }
};
