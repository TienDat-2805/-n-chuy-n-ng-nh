<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lecturers', function (Blueprint $table) {
            $table->string('availability_mode')->default('unrestricted')->after('phone');
        });

        DB::table('lecturers')
            ->whereNotNull('available_slots')
            ->orderBy('id')
            ->get(['id', 'available_slots'])
            ->each(function ($lecturer) {
                $slots = json_decode((string) $lecturer->available_slots, true);

                if (is_array($slots) && count($slots) > 0) {
                    DB::table('lecturers')
                        ->where('id', $lecturer->id)
                        ->update(['availability_mode' => 'limited']);
                }
            });
    }

    public function down(): void
    {
        Schema::table('lecturers', function (Blueprint $table) {
            $table->dropColumn('availability_mode');
        });
    }
};
