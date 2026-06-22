<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lecturer extends Model
{
    protected $fillable = [
        'name',
        'department',
        'email',
        'phone',
        'availability_mode',
        'available_slots',
    ];

    protected $casts = [
        'available_slots' => 'array',
    ];

    public function sectionInstructors()
    {
        return $this->hasMany(SectionInstructor::class);
    }

    public function sections()
    {
        return $this->belongsToMany(Section::class, 'section_instructors');
    }

    public function meetings()
    {
        return $this->hasMany(SectionMeeting::class);
    }
}
