<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_meetings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();

            $table->tinyInteger('day_of_week')->nullable();
            $table->tinyInteger('start_period')->nullable();
            $table->tinyInteger('end_period')->nullable();

            $table->string('week_pattern')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_meetings');
    }
};