<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appliance_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('interval_value');
            $table->enum('interval_unit', ['days', 'weeks', 'months', 'years', 'hours', 'km']);
            $table->enum('anchor_type', ['from_last_done', 'fixed_calendar'])->default('from_last_done');
            $table->date('anchor_date')->nullable();
            $table->dateTime('last_completed_at')->nullable();
            $table->double('last_metric_value')->nullable();
            $table->dateTime('next_due_at')->nullable();
            $table->double('next_due_at_value')->nullable();
            $table->boolean('is_confirmed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tasks');
    }
};
