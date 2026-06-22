<?php

namespace App\Http\Controllers;

use App\Models\Lecturer;
use App\Models\ScheduleConflict;
use App\Models\SectionInstructor;
use App\Models\SectionMeeting;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
    private const DAYS = [
        2 => 'Thứ 2',
        3 => 'Thứ 3',
        4 => 'Thứ 4',
        5 => 'Thứ 5',
        6 => 'Thứ 6',
        7 => 'Thứ 7',
    ];

    private const SESSIONS = [
        'morning' => 'Sáng',
        'afternoon' => 'Chiều',
    ];

    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $conflicts = ScheduleConflict::query()
            ->with(['meeting.section', 'conflictMeeting.section'])
            ->latest()
            ->limit(500)
            ->get();

        $conflictSectionIds = $conflicts
            ->flatMap(fn ($conflict) => [
                $conflict->meeting?->section_id,
                $conflict->conflictMeeting?->section_id,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $conflictMessagesBySection = [];

        foreach ($conflicts as $conflict) {
            foreach ([$conflict->meeting?->section_id, $conflict->conflictMeeting?->section_id] as $sectionId) {
                if (! $sectionId) {
                    continue;
                }

                $conflictMessagesBySection[$sectionId] ??= [];

                if (count($conflictMessagesBySection[$sectionId]) < 2) {
                    $conflictMessagesBySection[$sectionId][] = $conflict->message;
                }
            }
        }

        $subjects = Subject::query()
            ->withCount('sections')
            ->with(['sections' => function ($query) {
                $query
                    ->with(['lecturers', 'meetings.room', 'meetings.lecturer'])
                    ->orderBy('section_code');
            }])
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query
                        ->where('subject_code', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhereHas('sections', function ($sectionQuery) use ($keyword) {
                            $sectionQuery->where('section_code', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('sections.lecturers', function ($lecturerQuery) use ($keyword) {
                            $lecturerQuery->where('name', 'like', "%{$keyword}%");
                        });
                });
            })
            ->orderBy('subject_code')
            ->paginate(15)
            ->withQueryString();

        return view('subjects.index', [
            'subjects' => $subjects,
            'lecturers' => Lecturer::query()->orderBy('name')->get(['id', 'name']),
            'keyword' => $keyword,
            'conflictSectionIds' => $conflictSectionIds,
            'conflictMessagesBySection' => $conflictMessagesBySection,
            'days' => self::DAYS,
            'sessions' => self::SESSIONS,
        ]);
    }

    public function updateLecturerAvailability(Request $request, Lecturer $lecturer)
    {
        $data = $request->validate([
            'availability_mode' => ['required', 'in:unrestricted,limited'],
            'available_slots' => ['nullable', 'array'],
            'available_slots.*' => ['string'],
        ]);

        $mode = $data['availability_mode'];
        $normalizedSlots = $mode === 'limited'
            ? $this->normalizeSlots($data['available_slots'] ?? [])
            : [];

        $lecturer->update([
            'availability_mode' => $mode,
            'available_slots' => $normalizedSlots,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Đã cập nhật lịch có thể dạy của giảng viên.',
                'availability_mode' => $mode,
                'available_slots' => $normalizedSlots,
            ]);
        }

        return redirect()
            ->route('lecturers.index')
            ->with('success', 'Đã cập nhật lịch có thể dạy của giảng viên.');
    }

    public function attachLecturer(Request $request, Subject $subject)
    {
        $data = $request->validate([
            'lecturer_id' => ['required', 'integer', 'exists:lecturers,id'],
        ]);

        $subject->load('sections:id,subject_id,section_code');
        $lecturer = Lecturer::query()->findOrFail($data['lecturer_id']);

        if ($subject->sections->isEmpty()) {
            return $this->subjectActionError(
                $request,
                'Môn học này chưa có lớp học phần nên chưa thể gắn giảng viên.'
            );
        }

        $attached = 0;

        DB::transaction(function () use ($lecturer, $subject, &$attached) {
            foreach ($subject->sections as $section) {
                $link = SectionInstructor::firstOrCreate(
                    [
                        'section_id' => $section->id,
                        'lecturer_id' => $lecturer->id,
                    ],
                    [
                        'role' => 'Giảng viên',
                    ]
                );

                if ($link->wasRecentlyCreated) {
                    $attached++;
                }
            }
        });

        $message = $attached > 0
            ? "Đã gắn {$lecturer->name} vào môn {$subject->subject_code}."
            : "{$lecturer->name} đã có trong môn {$subject->subject_code}.";

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'attached' => $attached,
            ]);
        }

        return redirect()
            ->route('subjects.index', $request->only('keyword', 'page'))
            ->with('success', $message);
    }

    public function detachLecturer(Request $request, Subject $subject, Lecturer $lecturer)
    {
        $sectionIds = $subject->sections()->pluck('id');

        DB::transaction(function () use ($sectionIds, $lecturer) {
            SectionInstructor::query()
                ->whereIn('section_id', $sectionIds)
                ->where('lecturer_id', $lecturer->id)
                ->delete();

            SectionMeeting::query()
                ->whereIn('section_id', $sectionIds)
                ->where('lecturer_id', $lecturer->id)
                ->update(['lecturer_id' => null]);
        });

        $message = "Đã bỏ {$lecturer->name} khỏi môn {$subject->subject_code}.";

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
            ]);
        }

        return redirect()
            ->route('subjects.index', $request->only('keyword', 'page'))
            ->with('success', $message);
    }

    private function subjectActionError(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
            ], 422);
        }

        return redirect()
            ->route('subjects.index', $request->only('keyword', 'page'))
            ->with('error', $message);
    }

    private function normalizeSlots(array $slots): array
    {
        $valid = [];

        foreach (self::DAYS as $day => $label) {
            foreach (array_keys(self::SESSIONS) as $session) {
                $valid[] = "{$day}_{$session}";
            }
        }

        $validLookup = array_flip($valid);

        return array_values(array_filter(array_unique($slots), fn ($slot) => isset($validLookup[$slot])));
    }
}
