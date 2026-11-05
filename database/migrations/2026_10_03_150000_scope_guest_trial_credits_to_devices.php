<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('guest_trial_credits', 'legacy_guest_trial_ip_credits');

        Schema::create('guest_trial_credits', function (Blueprint $table) {
            $table->char('device_hash', 64)->primary();
            $table->unsignedTinyInteger('used_credits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_trial_credits');
        Schema::rename('legacy_guest_trial_ip_credits', 'guest_trial_credits');
    }
};
