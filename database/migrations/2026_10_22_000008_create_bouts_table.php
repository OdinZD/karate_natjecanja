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
        Schema::create('bouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('bracket');
            $table->unsignedTinyInteger('round');
            $table->unsignedSmallInteger('position');
            $table->foreignId('aka_entry_id')->nullable()->constrained('entries')->nullOnDelete();
            $table->foreignId('ao_entry_id')->nullable()->constrained('entries')->nullOnDelete();
            $table->foreignId('winner_entry_id')->nullable()->constrained('entries')->nullOnDelete();
            $table->unsignedTinyInteger('aka_score')->nullable();
            $table->unsignedTinyInteger('ao_score')->nullable();
            $table->string('decision')->nullable();
            $table->foreignId('next_bout_id')->nullable()->constrained('bouts')->nullOnDelete();
            $table->string('next_slot')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'bracket', 'round', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bouts');
    }
};
