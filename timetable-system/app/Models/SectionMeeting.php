<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SectionMeeting extends Model
{
    protected $fillable = [
        'section_id',
        'room_id',
        'lecturer_id',
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

    public function lecturer()
    {
        return $this->belongsTo(Lecturer::class);
    }

    public function displayLecturer()
    {
        return $this->lecturer ?: $this->section?->lecturers?->first();
    }

    public function displayLecturerName(): ?string
    {
        return $this->displayLecturer()?->name;
    }

    public function displaySectionCode(): ?string
    {
        $section = $this->section;

        if (! $section) {
            return null;
        }

        $subjectCode = trim((string) ($section->subject?->subject_code ?? ''));
        $baseCode = $subjectCode !== '' ? $subjectCode : $section->section_code;
        $lecturer = $this->displayLecturer();
        $lecturers = $section->lecturers ?? collect();

        if (! $lecturer || $lecturers->count() <= 1) {
            return $section->section_code ?: $baseCode;
        }

        $orderedLecturers = $lecturers
            ->unique('id')
            ->sortBy(fn ($item) => mb_strtolower($item->name, 'UTF-8') . '#' . $item->id)
            ->values();
        $index = $orderedLecturers->search(fn ($item) => (int) $item->id === (int) $lecturer->id);

        if ($index === false) {
            return $section->section_code ?: $baseCode;
        }

        return trim($baseCode . ' ' . ((int) $index + 1));
    }
}
