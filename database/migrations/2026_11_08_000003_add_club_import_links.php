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
        Schema::table('club_imports', function (Blueprint $table) {
            $table->timestamp('undone_at')->nullable()->after('errors');
        });

        Schema::table('entries', function (Blueprint $table) {
            $table->foreignId('club_import_id')->nullable()->after('seed')->constrained()->nullOnDelete();
        });

        Schema::table('competitors', function (Blueprint $table) {
            $table->foreignId('created_by_import_id')->nullable()->after('birth_date')->constrained('club_imports')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('competitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_import_id');
        });

        Schema::table('entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('club_import_id');
        });

        Schema::table('club_imports', function (Blueprint $table) {
            $table->dropColumn('undone_at');
        });
    }
};
