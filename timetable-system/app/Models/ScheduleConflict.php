<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleConflict extends Model
{
    protected $fillable = [
        'type',
        'section_meeting_id',
        'conflict_section_meeting_id',
        'message',
    ];

    public function meeting()
    {
        return $this->belongsTo(SectionMeeting::class, 'section_meeting_id');
    }

    public function conflictMeeting()
    {
        return $this->belongsTo(SectionMeeting::class, 'conflict_section_meeting_id');
    }
}