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
            $table->string('share_token_hash', 64)->nullable();
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zen_consultations', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn('share_token_hash');
        });
    }
};
