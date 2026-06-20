<?php

namespace App\Http\Controllers;

use App\Services\ScheduleConflictService;
use App\Services\TimetableSchedulerService;

class ScheduleController extends Controller
{
    public function generate(TimetableSchedulerService $scheduler, ScheduleConflictService $conflictService)
    {
        $result = $scheduler->generate();
        $conflicts = $conflictService->detect();

        $message = "Đã xếp {$result['scheduled']}/{$result['total']} môn học.";

        if ($result['unscheduled'] > 0) {
            $message .= " Còn {$result['unscheduled']} môn chưa xếp được do thiếu giảng viên, phòng hoặc khung giờ phù hợp.";

            if (! empty($result['reasons'])) {
                $message .= ' Lý do mẫu: ' . implode(' ', array_slice($result['reasons'], 0, 3));
            }
        }

        if ($conflicts > 0) {
            $message .= " Hệ thống phát hiện {$conflicts} xung đột cần kiểm tra.";
        }

        return redirect()
            ->route('imports.index')
            ->with($result['unscheduled'] > 0 || $conflicts > 0 ? 'error' : 'success', $message);
    }
}
