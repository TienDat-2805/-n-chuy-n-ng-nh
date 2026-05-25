<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('section_instructors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained('lecturers')->cascadeOnDelete();

            $table->integer('theory_hours')->nullable();
            $table->integer('practice_hours')->nullable();
            $table->integer('self_study_hours')->nullable();

            $table->string('role')->nullable();

            $table->timestamps();

            $table->unique(['section_id', 'lecturer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_instructors');
    }
};