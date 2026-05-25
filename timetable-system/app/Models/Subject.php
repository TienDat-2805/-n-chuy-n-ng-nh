<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    protected $fillable = [
        'subject_code',
        'name',
        'credits',
        'theory_credits',
        'practice_credits',
        'self_study_credits',
    ];

    public function sections()
    {
        return $this->hasMany(Section::class);
    }
}