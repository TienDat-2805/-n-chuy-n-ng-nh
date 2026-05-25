<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Section extends Model
{
    protected $fillable = [
        'subject_id',
        'program_id',
        'cohort_id',
        'section_code',
        'max_students',
        'teaching_mode',
        'teaching_language',
        'grading_owner',
        'support_request',
        'note',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function cohort()
    {
        return $this->belongsTo(Cohort::class);
    }

    public function instructors()
    {
        return $this->hasMany(SectionInstructor::class);
    }

    public function lecturers()
    {
        return $this->belongsToMany(Lecturer::class, 'section_instructors');
    }

    public function meetings()
    {
        return $this->hasMany(SectionMeeting::class);
    }
}