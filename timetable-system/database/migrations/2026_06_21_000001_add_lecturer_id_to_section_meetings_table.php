<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('section_meetings', 'lecturer_id')) {
            return;
        }

        Schema::table('section_meetings', function (Blueprint $table) {
            $table
                ->foreignId('lecturer_id')
                ->nullable()
                ->after('room_id')
                ->constrained('lecturers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('section_meetings', 'lecturer_id')) {
            return;
        }

        Schema::table('section_meetings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lecturer_id');
        });
    }
};
