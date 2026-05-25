<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();

            $table->string('subject_code')->unique();
            $table->string('name');
            $table->integer('credits')->nullable();

            $table->integer('theory_credits')->nullable();
            $table->integer('practice_credits')->nullable();
            $table->integer('self_study_credits')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};