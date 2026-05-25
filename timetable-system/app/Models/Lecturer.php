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
    ];

    public function sectionInstructors()
    {
        return $this->hasMany(SectionInstructor::class);
    }

    public function sections()
    {
        return $this->belongsToMany(Section::class, 'section_instructors');
    }
}