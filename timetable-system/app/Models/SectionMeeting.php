<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SectionMeeting extends Model
{
    protected $fillable = [
        'section_id',
        'room_id',
        'day_of_week',
        'start_period',
        'end_period',
        'week_pattern',
        'note',
    ];

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}