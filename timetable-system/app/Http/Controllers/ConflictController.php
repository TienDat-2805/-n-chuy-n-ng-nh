<?php

namespace App\Http\Controllers;

use App\Models\ScheduleConflict;
use App\Services\ScheduleConflictService;
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

    public function check(ScheduleConflictService $service)
    {
        $created = $service->detect();
        $message = "Đã kiểm tra xung đột. Phát hiện {$created} lỗi.";

        if (request()->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
            ]);
        }

        return back()->with('success', $message);
    }

    public function apply(Request $request, ScheduleConflictService $service)
    {
        $data = $request->validate([
            'conflict_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'between:2,7'],
            'start_period' => ['required', 'integer', 'between:1,12'],
            'end_period' => ['required', 'integer', 'between:1,12'],
            'room_id' => ['nullable', 'integer'],
        ]);

        $conflict = ScheduleConflict::query()->find($data['conflict_id']);
        if (! $conflict) {
            $message = 'Xung đột này không còn tồn tại. Vui lòng bấm kiểm tra xung đột lại.';

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                ], 409);
            }

            return redirect()
                ->route('imports.index')
                ->with('error', $message);
        }

        if (! $service->applySuggestion($conflict, $data)) {
            $message = 'Phương án này không còn hợp lệ. Vui lòng kiểm tra lại xung đột.';

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $message,
                ], 422);
            }

            return redirect()
                ->route('imports.index')
                ->with('error', $message);
        }

        $message = 'Đã áp dụng phương án sửa lịch và kiểm tra lại xung đột.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
            ]);
        }

        return redirect()
            ->route('imports.index')
            ->with('success', $message);
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
