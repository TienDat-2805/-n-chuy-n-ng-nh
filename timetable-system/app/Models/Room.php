<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = [
        'name',
        'type',
        'campus',
        'capacity',
    ];

    public function meetings()
    {
        return $this->hasMany(SectionMeeting::class);
    }
}