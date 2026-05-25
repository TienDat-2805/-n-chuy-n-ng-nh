<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SectionInstructor extends Model
{
    protected $fillable = [
        'section_id',
        'lecturer_id',
        'theory_hours',
        'practice_hours',
        'self_study_hours',
        'role',
    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function lecturer()
    {
        return $this->belongsTo(Lecturer::class);
    }
}