<?php

namespace App\Http\Controllers;

use App\Models\ScheduleConflict;
use App\Models\SectionMeeting;
use Illuminate\Http\Request;

class ConflictController extends Controller
{
    public function index()
    {
        $conflicts = ScheduleConflict::query()
            ->with([
                'meeting.section.subject',
                'meeting.section.lecturers',
                'meeting.room',
                'conflictMeeting.section.subject',
                'conflictMeeting.section.lecturers',
                'conflictMeeting.room',
            ])
            ->latest()
            ->paginate(20);

        return view('conflicts.index', [
            'conflicts' => $conflicts,
        ]);
    }

    public function check()
    {
        ScheduleConflict::query()->delete();

        $meetings = SectionMeeting::query()
            ->with([
                'section.subject',
                'section.lecturers',
                'room',
            ])
            ->whereNotNull('day_of_week')
            ->whereNotNull('start_period')
            ->whereNotNull('end_period')
            ->get();

        $created = 0;

        for ($i = 0; $i < $meetings->count(); $i++) {
            for ($j = $i + 1; $j < $meetings->count(); $j++) {
                $a = $meetings[$i];
                $b = $meetings[$j];

                if (!$this->isSameTime($a, $b)) {
                    continue;
                }

                if ($this->isSameSection($a, $b)) {
                    $created += $this->createConflict(
                        'section_conflict',
                        $a,
                        $b,
                        'Một lớp học phần có nhiều lịch bị trùng thời gian.'
                    );
                }

                if ($this->isSameRoom($a, $b)) {
                    $created += $this->createConflict(
                        'room_conflict',
                        $a,
                        $b,
                        'Một phòng học/địa điểm được sử dụng cho nhiều lớp cùng thời điểm.'
                    );
                }

                if ($this->hasSameLecturer($a, $b)) {
                    $created += $this->createConflict(
                        'lecturer_conflict',
                        $a,
                        $b,
                        'Một giảng viên bị phân công dạy nhiều lớp cùng thời điểm.'
                    );
                }
            }
        }

        return redirect()
            ->route('conflicts.index')
            ->with('success', "Đã kiểm tra xung đột. Phát hiện {$created} lỗi.");
    }

    private function isSameTime(SectionMeeting $a, SectionMeeting $b): bool
    {
        if ($a->day_of_week !== $b->day_of_week) {
            return false;
        }

        return $a->start_period <= $b->end_period
            && $b->start_period <= $a->end_period;
    }

    private function isSameSection(SectionMeeting $a, SectionMeeting $b): bool
    {
        return $a->section_id === $b->section_id;
    }

    private function isSameRoom(SectionMeeting $a, SectionMeeting $b): bool
    {
        if (!$a->room_id || !$b->room_id) {
            return false;
        }

        return $a->room_id === $b->room_id;
    }

    private function hasSameLecturer(SectionMeeting $a, SectionMeeting $b): bool
    {
        $lecturerIdsA = $a->section->lecturers->pluck('id')->toArray();
        $lecturerIdsB = $b->section->lecturers->pluck('id')->toArray();

        return count(array_intersect($lecturerIdsA, $lecturerIdsB)) > 0;
    }

    private function createConflict(
        string $type,
        SectionMeeting $a,
        SectionMeeting $b,
        string $message
    ): int {
        ScheduleConflict::create([
            'type' => $type,
            'section_meeting_id' => $a->id,
            'conflict_section_meeting_id' => $b->id,
            'message' => $message,
        ]);

        return 1;
    }
}