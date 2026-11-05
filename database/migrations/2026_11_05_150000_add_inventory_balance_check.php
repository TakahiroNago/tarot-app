<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite cannot add a constraint to an existing table, and MySQL before 8.0.16 parses this check
 * without enforcing it. Row locks in the services remain the main protection in every case.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'ec_inventory_balances_on_hand_covers_reserved';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ec_inventory_balances ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (on_hand >= reserved)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        match (DB::getDriverName()) {
            'mysql' => DB::statement('ALTER TABLE ec_inventory_balances DROP CHECK '.self::CONSTRAINT),
            'mariadb' => DB::statement('ALTER TABLE ec_inventory_balances DROP CONSTRAINT '.self::CONSTRAINT),
            default => null,
        };
    }
};
