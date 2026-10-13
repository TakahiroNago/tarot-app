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
        Schema::table('zen_consultations', function (Blueprint $table) {
            $table->date('daily_consultation_date')->nullable();
            $table->unique(['user_id', 'daily_consultation_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zen_consultations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'daily_consultation_date']);
            $table->dropColumn('daily_consultation_date');
        });
    }
};
