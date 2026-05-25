<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_conflicts', function (Blueprint $table) {
            $table->id();

            $table->string('type');
            $table->foreignId('section_meeting_id')->nullable()->constrained('section_meetings')->nullOnDelete();
            $table->foreignId('conflict_section_meeting_id')->nullable()->constrained('section_meetings')->nullOnDelete();

            $table->text('message');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_conflicts');
    }
};