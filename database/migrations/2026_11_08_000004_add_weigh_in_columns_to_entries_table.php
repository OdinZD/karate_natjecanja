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
        Schema::table('entries', function (Blueprint $table) {
            $table->decimal('weighed_weight', 5, 2)->nullable()->after('weight');
            $table->timestamp('weighed_at')->nullable()->after('weighed_weight');
            $table->timestamp('absent_at')->nullable()->after('weighed_at');
            $table->foreignId('moved_from_category_id')->nullable()->after('absent_at')->constrained('categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_from_category_id');
            $table->dropColumn(['weighed_weight', 'weighed_at', 'absent_at']);
        });
    }
};
