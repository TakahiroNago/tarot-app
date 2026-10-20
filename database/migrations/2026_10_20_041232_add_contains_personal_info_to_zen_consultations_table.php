<?php

use App\Services\PersonalInfoDetector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zen_consultations', function (Blueprint $table) {
            $table->boolean('contains_personal_info')->default(true)->index();
        });

        // Existing rows stay flagged unless their concern is verified to be free of detected personal information.
        $detector = new PersonalInfoDetector;
        DB::table('zen_consultations')->select(['id', 'concern'])->orderBy('id')->chunkById(200, function ($rows) use ($detector): void {
            $cleanIds = $rows
                ->filter(fn (object $row): bool => $detector->detect($row->concern) === [])
                ->pluck('id');

            DB::table('zen_consultations')->whereIn('id', $cleanIds)->update(['contains_personal_info' => false]);
        });
    }

    public function down(): void
    {
        Schema::table('zen_consultations', function (Blueprint $table) {
            $table->dropIndex(['contains_personal_info']);
            $table->dropColumn('contains_personal_info');
        });
    }
};
