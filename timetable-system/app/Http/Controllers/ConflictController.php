<?php

namespace App\Http\Controllers;

use App\Models\ScheduleConflict;
use App\Models\SectionMeeting;
use App\Services\ScheduleConflictService;
use Illuminate\Http\Request;

class ConflictController extends Controller
{
    private const TYPE_LABELS = [
        'room_conflict' => 'Trùng phòng',
        'lecturer_conflict' => 'Trùng giảng viên',
        'section_conflict' => 'Trùng tiết học',
        'mixed_conflict' => 'Nhiều lỗi',
        'invalid_time_conflict' => 'Sai khung tiết',
        'lecturer_availability_conflict' => 'Sai lịch giảng viên',
        'room_assignment_conflict' => 'Sai loại phòng',
        'lecturer_campus_conflict' => 'Giảng viên dạy nhiều cơ sở trong ngày',
    ];

    private const GROUPS = [
        'all' => 'Tất cả',
        'hard' => 'Lỗi cần xử lý',
        'warning' => 'Cảnh báo',
        'lecturer' => 'Giảng viên',
        'room' => 'Phòng học',
        'time' => 'Thời gian',
    ];

    private const GROUP_TYPES = [
        'hard' => [
            'room_conflict',
            'lecturer_conflict',
            'section_conflict',
            'mixed_conflict',
            'invalid_time_conflict',
            'lecturer_availability_conflict',
            'room_assignment_conflict',
        ],
        'warning' => [
            'lecturer_campus_conflict',
        ],
        'lecturer' => [
            'lecturer_conflict',
            'lecturer_availability_conflict',
            'lecturer_campus_conflict',
        ],
        'room' => [
            'room_conflict',
            'room_assignment_conflict',
        ],
        'time' => [
            'section_conflict',
            'invalid_time_conflict',
            'mixed_conflict',
        ],
    ];

    private const TYPE_ORDER = [
        'mixed_conflict' => 1,
        'lecturer_conflict' => 2,
        'room_conflict' => 3,
        'section_conflict' => 4,
        'invalid_time_conflict' => 5,
        'lecturer_availability_conflict' => 6,
        'room_assignment_conflict' => 7,
        'lecturer_campus_conflict' => 8,
    ];

    public function index(Request $request, ScheduleConflictService $service)
    {
        $selectedGroup = $request->query('group', 'all');
        $selectedType = $request->query('type', 'all');
        $keyword = trim((string) $request->query('keyword', ''));

        if (! isset(self::GROUPS[$selectedGroup])) {
            $selectedGroup = 'all';
        }

        if ($selectedType !== 'all' && ! isset(self::TYPE_LABELS[$selectedType])) {
            $selectedType = 'all';
        }

        if (
            $selectedGroup !== 'all'
            && $selectedType !== 'all'
            && ! in_array($selectedType, self::GROUP_TYPES[$selectedGroup] ?? [], true)
        ) {
            $selectedGroup = 'all';
        }

        $typeCounts = ScheduleConflict::query()
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $groupCounts = collect(self::GROUPS)
            ->mapWithKeys(function (string $label, string $group) use ($typeCounts) {
                if ($group === 'all') {
                    return [$group => $typeCounts->sum()];
                }

                return [
                    $group => collect(self::GROUP_TYPES[$group] ?? [])
                        ->sum(fn (string $type) => (int) $typeCounts->get($type, 0)),
                ];
            });

        $conflictQuery = ScheduleConflict::query()
            ->with([
                'meeting.section.subject',
                'meeting.section.lecturers',
                'meeting.room',
                'conflictMeeting.section.subject',
                'conflictMeeting.section.lecturers',
                'conflictMeeting.room',
            ]);

        if ($selectedGroup !== 'all') {
            $conflictQuery->whereIn('type', self::GROUP_TYPES[$selectedGroup] ?? []);
        }

        if ($selectedType !== 'all') {
            $conflictQuery->where('type', $selectedType);
        }

        if ($keyword !== '') {
            $this->applyKeywordFilter($conflictQuery, $keyword);
        }

        $orderSql = collect(self::TYPE_ORDER)
            ->map(fn (int $order, string $type) => "WHEN '{$type}' THEN {$order}")
            ->implode(' ');

        $conflicts = $conflictQuery
            ->orderByRaw("CASE type {$orderSql} ELSE 99 END")
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('conflicts.index', [
            'conflicts' => $conflicts,
            'summary' => $typeCounts,
            'typeCounts' => $typeCounts,
            'groupCounts' => $groupCounts,
            'typeLabels' => self::TYPE_LABELS,
            'groupLabels' => self::GROUPS,
            'selectedGroup' => $selectedGroup,
            'selectedType' => $selectedType,
            'keyword' => $keyword,
            'hardTypes' => self::GROUP_TYPES['hard'],
            'warningTypes' => self::GROUP_TYPES['warning'],
        ]);
    }

    private function applyKeywordFilter($query, string $keyword): void
    {
        $query->where(function ($query) use ($keyword) {
            $query
                ->where('message', 'like', "%{$keyword}%")
                ->orWhereHas('meeting.section', fn ($sectionQuery) => $sectionQuery->where('section_code', 'like', "%{$keyword}%"))
                ->orWhereHas('meeting.section.subject', fn ($subjectQuery) => $subjectQuery->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('meeting.section.lecturers', fn ($lecturerQuery) => $lecturerQuery->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('meeting.room', fn ($roomQuery) => $roomQuery
                    ->where('name', 'like', "%{$keyword}%")
                    ->orWhere('campus', 'like', "%{$keyword}%"))
                ->orWhereHas('conflictMeeting.section', fn ($sectionQuery) => $sectionQuery->where('section_code', 'like', "%{$keyword}%"))
                ->orWhereHas('conflictMeeting.section.subject', fn ($subjectQuery) => $subjectQuery->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('conflictMeeting.section.lecturers', fn ($lecturerQuery) => $lecturerQuery->where('name', 'like', "%{$keyword}%"))
                ->orWhereHas('conflictMeeting.room', fn ($roomQuery) => $roomQuery
                    ->where('name', 'like', "%{$keyword}%")
                    ->orWhere('campus', 'like', "%{$keyword}%"));
        });
    }

    public function suggestions(Request $request, ScheduleConflictService $service)
    {
        $data = $request->validate([
            'conflict_id' => ['required', 'integer', 'exists:schedule_conflicts,id'],
            'target_meeting_id' => ['required', 'integer', 'exists:section_meetings,id'],
        ]);

        $conflict = ScheduleConflict::query()
            ->with([
                'meeting.section.subject',
                'meeting.section.lecturers',
                'meeting.room',
                'conflictMeeting.section.subject',
                'conflictMeeting.section.lecturers',
                'conflictMeeting.room',
            ])
            ->findOrFail($data['conflict_id']);

        $targetMeeting = collect([$conflict->meeting, $conflict->conflictMeeting])
            ->filter()
            ->first(fn (SectionMeeting $meeting) => (int) $meeting->id === (int) $data['target_meeting_id']);

        if (! $targetMeeting) {
            return response()->json([
                'ok' => false,
                'message' => 'Lịch được chọn không thuộc cảnh báo này.',
            ], 422);
        }

        $targetLabel = $conflict->meeting && (int) $conflict->meeting->id === (int) $targetMeeting->id ? 'A' : 'B';
        $suggestions = collect($service->adjustmentSuggestionsForMeeting($conflict, $targetMeeting, 4))
            ->map(fn (array $suggestion) => array_merge($suggestion, [
                'meeting_id' => $targetMeeting->id,
                'target_label' => $targetLabel,
                'target_title' => $this->meetingTitle($targetMeeting),
            ]))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'suggestions' => $suggestions,
            'target' => [
                'meeting_id' => $targetMeeting->id,
                'label' => $targetLabel,
                'title' => $this->meetingTitle($targetMeeting),
            ],
        ]);
    }

    public function check(ScheduleConflictService $service)
    {
        $created = $service->detect();
        $message = "Đã kiểm tra xung đột. Phát hiện {$created} lỗi.";

        if (request()->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'remaining' => ScheduleConflict::query()->count(),
            ]);
        }

        return back()->with('success', $message);
    }

    public function apply(Request $request, ScheduleConflictService $service)
    {
        $data = $request->validate([
            'conflict_id' => ['nullable', 'integer'],
            'meeting_id' => ['nullable', 'integer', 'required_without:conflict_id', 'exists:section_meetings,id'],
            'day_of_week' => ['required', 'integer', 'between:2,7'],
            'start_period' => ['required', 'integer', 'between:1,12'],
            'end_period' => ['required', 'integer', 'between:1,12'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
        ]);

        $conflict = ! empty($data['conflict_id'])
            ? ScheduleConflict::query()->find($data['conflict_id'])
            : null;
        $meeting = ! empty($data['meeting_id'])
            ? SectionMeeting::query()->find($data['meeting_id'])
            : null;

        if (! $conflict && ! $meeting) {
            $message = 'Xung đột này không còn tồn tại. Vui lòng bấm kiểm tra xung đột lại.';

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                ], 409);
            }

            return redirect()
                ->route('conflicts.index')
                ->with('error', $message);
        }

        $applied = $meeting
            ? $service->applyMeetingSuggestion($meeting, $data)
            : $service->applySuggestion($conflict, $data);

        if (! $applied) {
            $message = 'Phương án này không còn hợp lệ. Vui lòng kiểm tra lại xung đột.';

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                ], 422);
            }

            return redirect()
                ->route('conflicts.index')
                ->with('error', $message);
        }

        $message = 'Đã áp dụng phương án sửa lịch và kiểm tra lại xung đột.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'remaining' => ScheduleConflict::query()->count(),
                'updated_meeting' => $this->meetingPayload($meeting ?: $conflict?->meeting),
            ]);
        }

        return redirect()
            ->route('conflicts.index')
            ->with('success', $message);
    }

    private function meetingTitle(?SectionMeeting $meeting): string
    {
        if (! $meeting) {
            return 'Chưa xác định';
        }

        $meeting->loadMissing('section.subject');
        $code = $meeting->section?->section_code;
        $subject = $meeting->section?->subject?->name;

        return trim(($code ? "{$code} - " : '') . ($subject ?: 'Chưa rõ môn học'));
    }

    private function meetingPayload(?SectionMeeting $meeting): ?array
    {
        if (! $meeting) {
            return null;
        }

        $meeting->refresh();
        $meeting->loadMissing(['section.subject', 'section.lecturers', 'room']);

        return [
            'id' => $meeting->id,
            'title' => $this->meetingTitle($meeting),
            'day_of_week' => $meeting->day_of_week,
            'start_period' => $meeting->start_period,
            'end_period' => $meeting->end_period,
            'room' => $meeting->room?->name,
            'campus' => $meeting->room?->campus,
            'lecturers' => $meeting->section?->lecturers?->pluck('name')->filter()->values()->all() ?? [],
        ];
    }

    public function autoSchedule(Request $request, ScheduleConflictService $service)
    {
        $result = $service->autoSchedule(80);

        $message = "Đã tự động tối ưu {$result['applied']} lượt. Còn {$result['remaining']} xung đột.";
        if ($result['stuck']) {
            $message .= ' Một số xung đột chưa có phương án phù hợp, cần xử lý thủ công.';
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $result['remaining'] === 0,
                'message' => $message,
                'result' => $result,
            ]);
        }

        return redirect()
            ->route('imports.index')
            ->with($result['remaining'] === 0 ? 'success' : 'error', $message);
    }
}
