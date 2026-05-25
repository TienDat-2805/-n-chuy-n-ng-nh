<?php

namespace App\Http\Controllers;

use App\Models\Lecturer;
use App\Models\Room;
use App\Models\Section;
use App\Models\SectionMeeting;
use Illuminate\Http\Request;

class TimetableController extends Controller
{
    private const MIN_PERIODS_PER_DAY = 10;
    private const MAX_PERIODS_PER_DAY = 12;

    public function index(Request $request)
    {
        $sectionId = $request->query('section_id');
        $lecturerId = $request->query('lecturer_id');
        $roomId = $request->query('room_id');

        $meetingsQuery = SectionMeeting::query()
            ->with([
                'section.subject',
                'section.program',
                'section.cohort',
                'section.lecturers',
                'room',
            ])
            ->whereNotNull('day_of_week')
            ->whereNotNull('start_period')
            ->whereNotNull('end_period');

        if ($sectionId) {
            $meetingsQuery->where('section_id', $sectionId);
        }

        if ($lecturerId) {
            $meetingsQuery->whereHas('section.lecturers', function ($query) use ($lecturerId) {
                $query->where('lecturers.id', $lecturerId);
            });
        }

        if ($roomId) {
            $meetingsQuery->where('room_id', $roomId);
        }

        $meetings = $meetingsQuery
            ->orderBy('day_of_week')
            ->orderBy('start_period')
            ->get();

        $maxPeriod = min(
            self::MAX_PERIODS_PER_DAY,
            max(self::MIN_PERIODS_PER_DAY, (int) $meetings->max('end_period'))
        );
        $periods = range(1, $maxPeriod);
        $grid = [];

        foreach ($periods as $period) {
            for ($day = 2; $day <= 6; $day++) {
                $grid[$period][$day] = [];
            }
        }

        foreach ($meetings as $meeting) {
            for ($period = $meeting->start_period; $period <= $meeting->end_period; $period++) {
                if ($period >= 1 && $period <= $maxPeriod && $meeting->day_of_week >= 2 && $meeting->day_of_week <= 6) {
                    $grid[$period][$meeting->day_of_week][] = $meeting;
                }
            }
        }

        return view('timetable.index', [
            'grid' => $grid,
            'periods' => $periods,
            'maxPeriod' => $maxPeriod,
            'meetings' => $meetings,
            'sections' => Section::with('subject')->orderBy('section_code')->get(),
            'lecturers' => Lecturer::orderBy('name')->get(),
            'rooms' => Room::orderBy('name')->get(),
            'selectedSectionId' => $sectionId,
            'selectedLecturerId' => $lecturerId,
            'selectedRoomId' => $roomId,
        ]);
    }
}
