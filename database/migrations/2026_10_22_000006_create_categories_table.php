<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tatami_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('discipline');
            $table->string('gender')->nullable();
            $table->unsignedSmallInteger('birth_year_from');
            $table->unsignedSmallInteger('birth_year_to');
            $table->decimal('weight_min', 5, 2)->nullable();
            $table->decimal('weight_max', 5, 2)->nullable();
            $table->unsignedSmallInteger('bout_duration_seconds')->default(120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
