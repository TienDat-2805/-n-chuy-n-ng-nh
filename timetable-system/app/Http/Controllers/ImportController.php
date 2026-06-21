<?php

namespace App\Http\Controllers;

use App\Models\Cohort;
use App\Models\ImportBatch;
use App\Models\Lecturer;
use App\Models\Program;
use App\Models\Room;
use App\Models\ScheduleConflict;
use App\Models\Section;
use App\Models\SectionInstructor;
use App\Models\SectionMeeting;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ScheduleConflictService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ImportController extends Controller
{
    private const MAX_PERIOD_PER_DAY = 12;
    private const CAMPUSES = [
        'Hòa Lạc' => 'Hòa Lạc',
        'Trịnh Văn Bô' => 'Trịnh Văn Bô',
        '144 Xuân Thủy' => '144 Xuân Thủy',
    ];

    public function index(Request $request)
    {
        $studyMode = $request->query('study_mode', 'all');
        $selectedCampus = $request->query('campus', 'all');
        $campuses = $this->campusOptions();

        if ($selectedCampus !== 'all' && ! isset($campuses[$selectedCampus])) {
            $selectedCampus = 'all';
        }

        $totalConflicts = ScheduleConflict::query()->count();
        $conflictMeetingIds = ScheduleConflict::query()
            ->limit(500)
            ->get(['section_meeting_id', 'conflict_section_meeting_id'])
            ->flatMap(fn ($conflict) => [
                $conflict->section_meeting_id,
                $conflict->conflict_section_meeting_id,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $meetings = SectionMeeting::query()
            ->with([
                'section.subject',
                'section.lecturers',
                'room',
            ])
            ->whereNotNull('day_of_week')
            ->whereNotNull('start_period')
            ->whereNotNull('end_period')
            ->whereBetween('day_of_week', [2, 7])
            ->when($selectedCampus !== 'all', function ($query) use ($selectedCampus) {
                $query->whereHas('room', fn ($roomQuery) => $roomQuery->where('campus', $selectedCampus));
            })
            ->when($studyMode === 'online', function ($query) {
                $query->whereHas('section', function ($sectionQuery) {
                    $sectionQuery
                        ->where('teaching_mode', 'like', '%Online%')
                        ->orWhere('teaching_mode', 'like', '%online%')
                        ->orWhere('teaching_mode', 'like', '%Zoom%')
                        ->orWhere('teaching_mode', 'like', '%LMS%');
                });
            })
            ->when($studyMode === 'direct', function ($query) {
                $query->whereHas('section', function ($sectionQuery) {
                    $sectionQuery
                        ->whereNull('teaching_mode')
                        ->orWhere(function ($modeQuery) {
                            $modeQuery
                                ->where('teaching_mode', 'not like', '%Online%')
                                ->where('teaching_mode', 'not like', '%online%')
                                ->where('teaching_mode', 'not like', '%Zoom%')
                                ->where('teaching_mode', 'not like', '%LMS%');
                        });
                });
            })
            ->orderBy('day_of_week')
            ->orderBy('start_period')
            ->get();

        $maxPeriod = min(
            self::MAX_PERIOD_PER_DAY,
            max(10, (int) $meetings->max('end_period'))
        );
        $periods = range(1, $maxPeriod);
        $grid = [];

        foreach ($periods as $period) {
            for ($day = 2; $day <= 7; $day++) {
                $grid[$period][$day] = [];
            }
        }

        foreach ($meetings as $meeting) {
            for ($period = $meeting->start_period; $period <= $meeting->end_period; $period++) {
                if ($period >= 1 && $period <= $maxPeriod && $meeting->day_of_week >= 2 && $meeting->day_of_week <= 7) {
                    $grid[$period][$meeting->day_of_week][] = $meeting;
                }
            }
        }

        $conflicts = collect();

        $totalSections = Section::query()->count();
        $sectionsWithMeetings = Section::query()->whereHas('meetings')->count();
        $sectionsWithLecturers = Section::query()->whereHas('lecturers')->count();
        $sectionsReadyToSchedule = Section::query()
            ->whereHas('lecturers')
            ->count();
        $invalidLecturerSections = Section::query()
            ->whereDoesntHave('lecturers')
            ->count();

        return view('imports.index', [
            'grid' => $grid,
            'periods' => $periods,
            'maxPeriod' => $maxPeriod,
            'meetings' => $meetings,
            'latestImport' => ImportBatch::query()->latest()->first(),
            'conflicts' => $conflicts,
            'totalConflicts' => $totalConflicts,
            'conflictMeetingIds' => $conflictMeetingIds,
            'studyMode' => $studyMode,
            'campuses' => $campuses,
            'selectedCampus' => $selectedCampus,
            'dataQuality' => [
                'total_sections' => $totalSections,
                'sections_with_meetings' => $sectionsWithMeetings,
                'sections_with_lecturers' => $sectionsWithLecturers,
                'sections_ready_to_schedule' => $sectionsReadyToSchedule,
                'sections_missing_meetings' => max(0, $totalSections - $sectionsWithMeetings),
                'sections_missing_valid_lecturers' => $invalidLecturerSections,
            ],
        ]);
    }

    private function campusOptions(): array
    {
        $campuses = self::CAMPUSES;

        Room::query()
            ->whereNotNull('campus')
            ->where('campus', '!=', '')
            ->distinct()
            ->orderBy('campus')
            ->pluck('campus')
            ->each(function (string $campus) use (&$campuses) {
                $campuses[$campus] ??= $campus;
            });

        return $campuses;
    }

    public function exportTimetable(Request $request)
    {
        $studyMode = $request->query('study_mode', 'all');
        $selectedCampus = $request->query('campus', 'all');
        $campuses = $this->campusOptions();

        if ($selectedCampus !== 'all' && ! isset($campuses[$selectedCampus])) {
            $selectedCampus = 'all';
        }

        $conflictMeetingIds = ScheduleConflict::query()
            ->limit(500)
            ->get(['section_meeting_id', 'conflict_section_meeting_id'])
            ->flatMap(fn ($conflict) => [
                $conflict->section_meeting_id,
                $conflict->conflict_section_meeting_id,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $meetings = SectionMeeting::query()
            ->with([
                'section.subject',
                'section.lecturers',
                'room',
            ])
            ->whereNotNull('day_of_week')
            ->whereNotNull('start_period')
            ->whereNotNull('end_period')
            ->whereBetween('day_of_week', [2, 7])
            ->when($selectedCampus !== 'all', function ($query) use ($selectedCampus) {
                $query->whereHas('room', fn ($roomQuery) => $roomQuery->where('campus', $selectedCampus));
            })
            ->when($studyMode === 'online', function ($query) {
                $query->whereHas('section', function ($sectionQuery) {
                    $sectionQuery
                        ->where('teaching_mode', 'like', '%Online%')
                        ->orWhere('teaching_mode', 'like', '%online%')
                        ->orWhere('teaching_mode', 'like', '%Zoom%')
                        ->orWhere('teaching_mode', 'like', '%LMS%');
                });
            })
            ->when($studyMode === 'direct', function ($query) {
                $query->whereHas('section', function ($sectionQuery) {
                    $sectionQuery
                        ->whereNull('teaching_mode')
                        ->orWhere(function ($modeQuery) {
                            $modeQuery
                                ->where('teaching_mode', 'not like', '%Online%')
                                ->where('teaching_mode', 'not like', '%online%')
                                ->where('teaching_mode', 'not like', '%Zoom%')
                                ->where('teaching_mode', 'not like', '%LMS%');
                        });
                });
            })
            ->orderBy('day_of_week')
            ->orderBy('start_period')
            ->get();

        $maxPeriod = min(
            self::MAX_PERIOD_PER_DAY,
            max(10, (int) $meetings->max('end_period'))
        );

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Thoi khoa bieu');

        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', 'BẢNG THỜI KHÓA BIỂU');
        $sheet->mergeCells('A2:G2');
        $sheet->setCellValue('A2', 'Cơ sở: ' . ($selectedCampus === 'all' ? 'Tất cả cơ sở' : ($campuses[$selectedCampus] ?? $selectedCampus)));
        $sheet->mergeCells('A3:G3');
        $sheet->setCellValue('A3', 'Xuất lúc: ' . now()->format('d/m/Y H:i'));

        $headers = ['Tiết', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(65 + $index) . '5', $header);
        }

        for ($period = 1; $period <= $maxPeriod; $period++) {
            $row = $period + 5;
            $sheet->setCellValue("A{$row}", "Tiết {$period}");

            for ($day = 2; $day <= 7; $day++) {
                $cellMeetings = $meetings
                    ->filter(fn ($meeting) => (int) $meeting->day_of_week === $day
                        && (int) $meeting->start_period <= $period
                        && (int) $meeting->end_period >= $period)
                    ->values();

                $lines = $cellMeetings
                    ->map(function ($meeting) use ($period) {
                        $section = $meeting->section;
                        $subject = $section?->subject;
                        $lecturers = $section?->lecturers?->pluck('name')->filter()->join(', ');
                        $room = $meeting->room?->name;
                        $isStart = (int) $meeting->start_period === (int) $period;

                        if (! $isStart) {
                            return 'Tiếp tục: ' . ($section?->section_code ?? 'Lớp học phần');
                        }

                        return collect([
                            $section?->section_code,
                            $subject?->name,
                            'Tiết ' . $meeting->start_period . '-' . $meeting->end_period,
                            $lecturers ? 'GV: ' . $lecturers : null,
                            $room ? 'Phòng: ' . $room : null,
                        ])->filter()->join("\n");
                    })
                    ->filter()
                    ->join("\n\n");

                $sheet->setCellValue(chr(64 + $day) . $row, $lines !== '' ? $lines : '-');
            }
        }

        $lastRow = $maxPeriod + 5;
        $sheet->getStyle('A1:G3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A5:G5')->getFont()->setBold(true);
        $sheet->getStyle('A5:G5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF1FF');
        $sheet->getStyle("A5:G{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A5:G{$lastRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);

        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setWidth($column === 'A' ? 12 : 34);
        }

        for ($row = 6; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(92);
        }

        $fileName = 'thoi-khoa-bieu-' . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request, ScheduleConflictService $conflictService)
    {
        @set_time_limit(180);

        $request->validate([
            'excel_file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        $file = $request->file('excel_file');
        $spreadsheet = IOFactory::load($file->getRealPath());

        $sheet = $this->findTimetableSheet($spreadsheet);

        if (!$sheet) {
            return back()->with('error', 'Không tìm thấy sheet có cấu trúc thời khóa biểu. File cần có các cột như Mã học phần, Tên học phần, Mã lớp học phần.');
        }

        $sheetName = $sheet->getTitle();
        $rows = $sheet->toArray(null, true, true, true);
        $isSchoolwideFormat = $this->isSchoolwideTimetableSheet($sheet);
        $dynamicSchema = $isSchoolwideFormat ? null : $this->detectDynamicSchema($sheet);

        $this->resetImportedData();

        $batch = ImportBatch::create([
            'file_name' => $file->getClientOriginalName(),
            'sheet_name' => $sheetName,
            'total_rows' => count($rows),
            'success_rows' => 0,
            'failed_rows' => 0,
        ]);

        $success = 0;
        $failed = 0;
        $errors = [];
        $lastSubjectCode = null;
        $lastSubjectName = null;
        $lastCredits = null;
        $lastLecturerName = null;
        $lastDepartment = null;
        $lastEmail = null;
        $lastPhone = null;
        $lastRoomName = null;
        $dynamicState = [];

        if ($isSchoolwideFormat) {
            foreach ($rows as $index => $row) {
                if ($index < 11) {
                    continue;
                }

                try {
                    if ($this->importSchoolwideSchedulingRow($row, $index)) {
                        $success++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = 'Dòng ' . $index . ': ' . $e->getMessage();
                }
            }

            $batch->update([
                'success_rows' => $success,
                'failed_rows' => $failed,
                'error_log' => implode("\n", $errors),
            ]);

            return redirect()
                ->route('imports.index')
                ->with('success', "Đã import {$success} dòng hợp lệ, lỗi {$failed}. Dữ liệu được gom theo môn học và giảng viên. Hãy cấu hình ngày/buổi giảng viên có thể dạy trong trang Môn học rồi bấm xếp lịch.");
        }

        foreach ($rows as $index => $row) {
            if ($dynamicSchema) {
                if ($index < $dynamicSchema['data_row']) {
                    continue;
                }

                try {
                    if ($this->importDynamicRow($row, $dynamicSchema, $dynamicState)) {
                        $success++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = 'DÃ²ng ' . $index . ': ' . $e->getMessage();
                }

                continue;
            }

            if ($isSchoolwideFormat) {
                if ($index < 11) {
                    continue;
                }

                try {
                    $sectionCode = $this->cleanText($row['B'] ?? '');
                    $subjectName = $this->cleanText($row['C'] ?? '');
                    $subjectCode = $this->cleanText($row['D'] ?? '');
                    $dayRaw = $this->cleanText($row['E'] ?? '');
                    $periodRaw = $this->cleanText($row['F'] ?? '');
                    $credits = $this->toInt($row['G'] ?? null);
                    $group = $this->cleanText($row['H'] ?? '');
                    $startDate = $this->cleanText($row['I'] ?? '');
                    $endDate = $this->cleanText($row['J'] ?? '');
                    $roomName = $this->cleanText($row['K'] ?? '');
                    $lecturerName = $this->cleanText($row['L'] ?? '');

                    if ($sectionCode === '' || $subjectName === '') {
                        continue;
                    }

                    if ($subjectCode === '') {
                        $subjectCode = $this->guessSubjectCodeFromSectionCode($sectionCode);
                    }

                    if ($subjectCode === '') {
                        $subjectCode = $sectionCode;
                    }

                    $subject = Subject::updateOrCreate(
                        ['subject_code' => $subjectCode],
                        [
                            'name' => $subjectName,
                            'credits' => $credits,
                        ]
                    );

                    $noteParts = [];

                    if ($startDate !== '' || $endDate !== '') {
                        $noteParts[] = 'Thời gian học: ' . trim($startDate . ' - ' . $endDate, ' -');
                    }

                    if ($group !== '') {
                        $noteParts[] = 'Nhóm: ' . $group;
                    }

                    $section = Section::updateOrCreate(
                        ['section_code' => $sectionCode],
                        [
                            'subject_id' => $subject->id,
                            'note' => $noteParts ? implode("\n", $noteParts) : null,
                        ]
                    );

                    if ($this->isValidLecturerName($lecturerName)) {
                        $lecturer = Lecturer::firstOrCreate(
                            [
                                'name' => $lecturerName,
                                'email' => null,
                            ]
                        );

                        SectionInstructor::updateOrCreate(
                            [
                                'section_id' => $section->id,
                                'lecturer_id' => $lecturer->id,
                            ],
                            [
                                'role' => 'Giảng viên phụ trách',
                            ]
                        );
                    }

                    $room = null;
                    if ($roomName !== '') {
                        $room = Room::firstOrCreate(
                            ['name' => $roomName],
                            [
                                'type' => $this->guessRoomType($roomName),
                                'campus' => $this->guessCampus($roomName),
                            ]
                        );
                    }

                    $meetings = $this->normalizeMeetings($this->parseSchoolwideMeetings($dayRaw, $periodRaw));

                    foreach ($meetings as $meeting) {
                        SectionMeeting::updateOrCreate(
                            [
                                'section_id' => $section->id,
                                'day_of_week' => $meeting['day_of_week'],
                                'start_period' => $meeting['start_period'],
                                'end_period' => $meeting['end_period'],
                            ],
                            [
                                'room_id' => $room?->id,
                                'note' => ($startDate !== '' || $endDate !== '')
                                    ? 'Thời gian học: ' . trim($startDate . ' - ' . $endDate, ' -')
                                    : null,
                            ]
                        );
                    }

                    $success++;
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = 'Dòng ' . $index . ': ' . $e->getMessage();
                }

                continue;
            }

            if ($index < 6) {
                continue;
            }

            try {
                $subjectCode = $this->cleanText($row['A'] ?? '');
                $subjectName = $this->cleanText($row['B'] ?? '');
                $credits = $this->toInt($row['C'] ?? null);

                if ($subjectCode !== '') {
                    $lastSubjectCode = $subjectCode;
                } else {
                    $subjectCode = $lastSubjectCode;
                }

                if ($subjectName !== '') {
                    $lastSubjectName = $subjectName;
                } else {
                    $subjectName = $lastSubjectName;
                }

                if ($credits !== null) {
                    $lastCredits = $credits;
                } else {
                    $credits = $lastCredits;
                }

                $sectionCode = $this->cleanText($row['D'] ?? '');

                if ($subjectCode === null || $subjectCode === '' || $subjectName === null || $subjectName === '' || $sectionCode === '') {
                    continue;
                }

                $theoryCredits = $this->toInt($row['E'] ?? null);
                $practiceCredits = $this->toInt($row['F'] ?? null);
                $selfStudyCredits = $this->toInt($row['G'] ?? null);

                $cohortName = $this->cleanText($row['H'] ?? '');
                $programCode = $this->cleanText($row['I'] ?? '');
                $maxStudents = $this->toInt($row['J'] ?? null);

                $oldTime = $this->cleanText($row['K'] ?? '');

                $dayRaw = $this->cleanText($row['L'] ?? '');
                $startRaw = $this->cleanText($row['M'] ?? '');
                $endRaw = $this->cleanText($row['N'] ?? '');

                $lecturerName = $this->cleanText($row['O'] ?? '');
                $department = $this->cleanText($row['P'] ?? '');
                $email = $this->cleanText($row['Q'] ?? '');
                $phone = $this->cleanText($row['R'] ?? '');

                if ($lecturerName !== '') {
                    $lastLecturerName = $lecturerName;
                } else {
                    $lecturerName = $lastLecturerName;
                }

                if ($department !== '') {
                    $lastDepartment = $department;
                } else {
                    $department = $lastDepartment;
                }

                if ($email !== '') {
                    $lastEmail = $email;
                } else {
                    $email = $lastEmail;
                }

                if ($phone !== '') {
                    $lastPhone = $phone;
                } else {
                    $phone = $lastPhone;
                }

                $theoryHours = $this->toInt($row['S'] ?? null);
                $practiceHours = $this->toInt($row['T'] ?? null);
                $selfStudyHours = $this->toInt($row['U'] ?? null);

                $roomName = $this->cleanText($row['V'] ?? '');

                if ($roomName !== '') {
                    $lastRoomName = $roomName;
                } else {
                    $roomName = $lastRoomName;
                }

                $teachingMode = $this->cleanText($row['W'] ?? '');
                $teachingLanguage = $this->cleanText($row['X'] ?? '');
                $supportRequest = $this->cleanText($row['Y'] ?? '');
                $gradingOwner = $this->cleanText($row['Z'] ?? '');

                $subject = Subject::updateOrCreate(
                    ['subject_code' => $subjectCode],
                    [
                        'name' => $subjectName,
                        'credits' => $credits,
                        'theory_credits' => $theoryCredits,
                        'practice_credits' => $practiceCredits,
                        'self_study_credits' => $selfStudyCredits,
                    ]
                );

                $program = null;
                if ($programCode !== '') {
                    $program = Program::firstOrCreate(
                        ['code' => $programCode],
                        ['name' => $programCode]
                    );
                }

                $cohort = null;
                if ($cohortName !== '') {
                    $cohort = Cohort::firstOrCreate([
                        'name' => $cohortName,
                    ]);
                }

                $section = Section::updateOrCreate(
                    ['section_code' => $sectionCode],
                    [
                        'subject_id' => $subject->id,
                        'program_id' => $program?->id,
                        'cohort_id' => $cohort?->id,
                        'max_students' => $maxStudents,
                        'teaching_mode' => $teachingMode ?: null,
                        'teaching_language' => $teachingLanguage ?: null,
                        'grading_owner' => $gradingOwner ?: null,
                        'support_request' => $supportRequest ?: null,
                        'note' => $oldTime ? 'Thời gian năm ngoái: ' . $oldTime : null,
                    ]
                );

                
                if ($this->isValidLecturerName($lecturerName)) {
                    $lecturer = Lecturer::firstOrCreate(
                        [
                            'name' => $lecturerName,
                            'email' => $email ?: null,
                        ],
                        [
                            'department' => $department ?: null,
                            'phone' => $phone ?: null,
                        ]
                    );

                    SectionInstructor::updateOrCreate(
                        [
                            'section_id' => $section->id,
                            'lecturer_id' => $lecturer->id,
                        ],
                        [
                            'theory_hours' => $theoryHours,
                            'practice_hours' => $practiceHours,
                            'self_study_hours' => $selfStudyHours,
                            'role' => 'Giảng viên phụ trách',
                        ]
                    );
                }

                $room = null;
                if ($roomName !== '') {
                    $room = Room::firstOrCreate(
                        ['name' => $roomName],
                        [
                            'type' => $this->guessRoomType($roomName),
                            'campus' => $this->guessCampus($roomName),
                        ]
                    );
                }

                $meetings = $this->normalizeMeetings($this->parseMeetings($dayRaw, $startRaw, $endRaw));

                foreach ($meetings as $meeting) {
                    SectionMeeting::updateOrCreate(
                        [
                            'section_id' => $section->id,
                            'day_of_week' => $meeting['day_of_week'],
                            'start_period' => $meeting['start_period'],
                            'end_period' => $meeting['end_period'],
                        ],
                        [
                            'room_id' => $room?->id,
                        ]
                    );
                }

                $success++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = 'Dòng ' . $index . ': ' . $e->getMessage();
            }
        }

        $batch->update([
            'success_rows' => $success,
            'failed_rows' => $failed,
            'error_log' => implode("\n", $errors),
        ]);

        $scheduleResult = $isSchoolwideFormat
            ? ['applied' => 0, 'remaining' => $conflictService->detect(), 'stuck' => false]
            : $conflictService->autoSchedule(20);
        $message = "Đã import {$success} dòng hợp lệ, lỗi {$failed}. ";
        $message .= $isSchoolwideFormat
            ? "Hệ thống đã kiểm định lịch toàn trường, còn {$scheduleResult['remaining']} xung đột cần rà soát."
            : "Hệ thống đã tự động xếp/tối ưu {$scheduleResult['applied']} lượt, còn {$scheduleResult['remaining']} xung đột cần rà soát.";

        return redirect()
            ->route('imports.index')
            ->with('success', $message);
    }

    private function importSchoolwideSchedulingRow(array $row, int $index): bool
    {
        $subjectName = $this->cleanText($row['C'] ?? '');
        $subjectCode = $this->cleanText($row['D'] ?? '');
        $lecturerRaw = $this->cleanText($row['L'] ?? '');

        if ($subjectName === '' && $subjectCode === '' && $lecturerRaw === '') {
            return false;
        }

        if ($subjectCode === '' || $subjectName === '') {
            return false;
        }

        $subject = Subject::updateOrCreate(
            ['subject_code' => $subjectCode],
            [
                'name' => $subjectName,
                'credits' => null,
            ]
        );

        $section = Section::updateOrCreate(
            ['section_code' => $subjectCode],
            [
                'subject_id' => $subject->id,
                'note' => 'Dữ liệu được gom theo môn học để hệ thống tự xếp lịch mới.',
            ]
        );

        foreach ($this->splitLecturerNames($lecturerRaw) as $lecturerName) {
            $lecturer = Lecturer::firstOrCreate(
                [
                    'name' => $lecturerName,
                    'email' => null,
                ]
            );

            SectionInstructor::updateOrCreate(
                [
                    'section_id' => $section->id,
                    'lecturer_id' => $lecturer->id,
                ],
                [
                    'role' => 'Giảng viên phụ trách',
                ]
            );
        }

        return true;
    }

    private function splitLecturerNames(string $value): array
    {
        $value = preg_replace('/\s+(và|va|and)\s+/iu', ';', $value);
        $parts = preg_split('/[\n;,]+/u', (string) $value) ?: [];
        $names = [];

        foreach ($parts as $part) {
            $name = $this->cleanText($part);

            if ($this->isValidLecturerName($name)) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    private function cleanText($value): string
    {
        $value = trim((string)$value);
        $value = str_replace("\r", "\n", $value);
        $value = preg_replace("/[ \t]+/", ' ', $value);
        $value = preg_replace("/\n+/", "\n", $value);

        return trim($value);
    }

    private function toInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        $value = preg_replace('/[^0-9]/', '', (string)$value);

        if ($value === '') {
            return null;
        }

        return (int)$value;
    }

    private function isValidLecturerName(?string $name): bool
    {
        $name = trim((string)$name);

        if ($name === '') {
            return false;
        }

        $lowerName = mb_strtolower($name, 'UTF-8');

        $invalidKeywords = [
            'phòng đào tạo',
            'đào tạo điều phối',
            'điều phối',
            'trường đại học',
            'đại học',
            'viện đào tạo',
            'khoa',
            'bộ môn',
            'chưa xác định',
            'thứ',
            'buổi sáng',
            'buổi chiều',
            'sáng',
            'chiều',
            'tối',
            'online',
            'zoom',
            'lms',
        ];

        foreach ($invalidKeywords as $keyword) {
            if (str_contains($lowerName, $keyword)) {
                return false;
            }
        }

        /*
        * Loại các dòng ghi chú có ngoặc, ví dụ:
        * Hai (Thứ 2 buổi sáng và chiều)
        */
        if (str_contains($name, '(') || str_contains($name, ')')) {
            return false;
        }

        $parts = preg_split('/\s+/', $name);

        if (count($parts) < 2) {
            return false;
        }

        if (count($parts) > 7 && ! preg_match('/\b(PGS|TS|ThS|GS|Dr)\b/ui', $name)) {
            return false;
        }

        if (preg_match('/^(phòng|ban|khoa|viện|trường|bộ môn|trung tâm)\b/ui', $lowerName)) {
            return false;
        }

        if (!preg_match('/[a-zA-ZÀ-ỹ]/u', $name)) {
            return false;
        }

        return true;
    }

    private function guessRoomType(string $roomName): ?string
    {
        $normalizedRoom = $this->normalizeHeader($roomName);
        $lowerRoom = $normalizedRoom;

        if (
            str_contains($normalizedRoom, 'zoom')
            || str_contains($normalizedRoom, 'online')
            || str_contains($normalizedRoom, 'lms')
            || str_contains($normalizedRoom, 'truc tuyen')
        ) {
            return 'online';
        }

        if (str_contains($lowerRoom, 'lab') || str_contains($lowerRoom, 'thực hành')) {
            return 'lab';
        }

        if (str_contains($normalizedRoom, 'thuc hanh')) {
            return 'lab';
        }

        if (
            str_contains($normalizedRoom, 'hoa lac')
            || str_contains($normalizedRoom, 'ho lac')
            || str_contains($normalizedRoom, 'my dinh')
            || str_contains($normalizedRoom, 'campus')
            || str_contains($normalizedRoom, 'co so')
        ) {
            return 'campus';
        }

        if (
            str_contains($normalizedRoom, 'phong')
            || preg_match('/\b[A-Z]?\d{3,4}[A-Z]?\b/i', $roomName)
            || preg_match('/\b[A-Z]\s*[-.]?\s*\d{2,4}\b/i', $roomName)
        ) {
            return 'room';
        }

        return 'location';
    }

    private function guessCampus(string $roomName): ?string
    {
        $lowerRoom = mb_strtolower($roomName, 'UTF-8');

        if (str_contains($lowerRoom, 'hòa lạc') || str_contains($lowerRoom, 'hoà lạc')) {
            return 'Hòa Lạc';
        }

        if (str_contains($lowerRoom, 'mỹ đình')) {
            return 'Mỹ Đình';
        }

        return null;
    }

    private function parseMeetings(string $dayRaw, string $startRaw, string $endRaw): array
    {
        $days = $this->extractNumbers($dayRaw);
        $starts = $this->extractNumbers($startRaw);
        $ends = $this->extractNumbers($endRaw);

        $meetings = [];

        if (empty($days) || empty($starts) || empty($ends)) {
            return $meetings;
        }

        /*
         * Trường hợp đơn giản:
         * Thứ = 3, tiết đầu = 2, tiết cuối = 5
         */
        if (count($days) === 1 && count($starts) === 1 && count($ends) === 1) {
            $meetings[] = [
                'day_of_week' => $days[0],
                'start_period' => $starts[0],
                'end_period' => $ends[0],
            ];

            return $meetings;
        }

        /*
         * Trường hợp nhiều dòng:
         * L: "- 3 và 5\n- 4 và 6"
         * M: "2\n6"
         * N: "5\n9"
         *
         * Nghĩa là:
         * Thứ 3,5 học tiết 2-5
         * Thứ 4,6 học tiết 6-9
         */
        $dayLines = preg_split('/\n+/', $dayRaw);
        $startLines = preg_split('/\n+/', $startRaw);
        $endLines = preg_split('/\n+/', $endRaw);

        foreach ($dayLines as $lineIndex => $dayLine) {
            $lineDays = $this->extractNumbers($dayLine);

            $start = $this->toInt($startLines[$lineIndex] ?? null);
            $end = $this->toInt($endLines[$lineIndex] ?? null);

            if (!$start || !$end) {
                continue;
            }

            foreach ($lineDays as $day) {
                $meetings[] = [
                    'day_of_week' => $day,
                    'start_period' => $start,
                    'end_period' => $end,
                ];
            }
        }

        /*
         * Fallback nếu dữ liệu không theo dòng nhưng có nhiều thứ.
         */
        if (empty($meetings) && count($starts) === 1 && count($ends) === 1) {
            foreach ($days as $day) {
                $meetings[] = [
                    'day_of_week' => $day,
                    'start_period' => $starts[0],
                    'end_period' => $ends[0],
                ];
            }
        }

        return $meetings;
    }

    private function extractNumbers(string $value): array
    {
        preg_match_all('/\d+/', $value, $matches);

        return array_map('intval', $matches[0] ?? []);
    }

    private function parseSchoolwideMeetings(string $dayRaw, string $periodRaw): array
    {
        $days = $this->extractNumbers($dayRaw);
        $periods = $this->extractNumbers($periodRaw);
        $meetings = [];

        if (empty($days) || empty($periods)) {
            return $meetings;
        }

        $start = $periods[0];
        $end = $periods[1] ?? $periods[0];

        foreach ($days as $day) {
            $meetings[] = [
                'day_of_week' => $day,
                'start_period' => $start,
                'end_period' => $end,
            ];
        }

        return $meetings;
    }

    private function guessSubjectCodeFromSectionCode(string $sectionCode): string
    {
        if (preg_match('/^([A-Z]{2,}\d{4})\d{2}$/', $sectionCode, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function importDynamicRow(array $row, array $schema, array &$state): bool
    {
        $sectionCode = $this->schemaText($row, $schema, 'section_code');
        $rawSubjectCode = $this->schemaText($row, $schema, 'subject_code');
        $rawSubjectName = $this->schemaText($row, $schema, 'subject_name');
        $credits = $this->schemaInt($row, $schema, 'credits');

        if ($sectionCode === '') {
            return false;
        }

        if ($rawSubjectCode !== '') {
            $state['subject_code'] = $rawSubjectCode;
            $subjectCode = $rawSubjectCode;
        } elseif ($rawSubjectName === '' && isset($state['subject_code'])) {
            $subjectCode = $state['subject_code'];
        } else {
            $subjectCode = $this->guessSubjectCodeFromSectionCode($sectionCode);
        }

        if ($rawSubjectName !== '') {
            $state['subject_name'] = $rawSubjectName;
            $subjectName = $rawSubjectName;
        } else {
            $subjectName = $state['subject_name'] ?? '';
        }

        if ($credits !== null) {
            $state['credits'] = $credits;
        } elseif ($rawSubjectCode === '' && $rawSubjectName === '' && isset($state['credits'])) {
            $credits = $state['credits'];
        }

        if ($subjectCode === '') {
            $subjectCode = $sectionCode;
        }

        if ($subjectName === '') {
            return false;
        }

        $subjectData = ['name' => $subjectName];
        $this->putIfNotNull($subjectData, 'credits', $credits);
        $this->putIfNotNull($subjectData, 'theory_credits', $this->schemaInt($row, $schema, 'theory_credits'));
        $this->putIfNotNull($subjectData, 'practice_credits', $this->schemaInt($row, $schema, 'practice_credits'));
        $this->putIfNotNull($subjectData, 'self_study_credits', $this->schemaInt($row, $schema, 'self_study_credits'));

        $subject = Subject::updateOrCreate(
            ['subject_code' => $subjectCode],
            $subjectData
        );

        $program = null;
        $programCode = $this->schemaText($row, $schema, 'program_code');
        if ($programCode !== '') {
            $program = Program::firstOrCreate(
                ['code' => $programCode],
                ['name' => $programCode]
            );
        }

        $cohort = null;
        $cohortName = $this->schemaText($row, $schema, 'cohort_name');
        if ($cohortName !== '') {
            $cohort = Cohort::firstOrCreate(['name' => $cohortName]);
        }

        $group = $this->schemaText($row, $schema, 'group');
        $oldTime = $this->schemaText($row, $schema, 'old_time');
        $startDate = $this->schemaText($row, $schema, 'start_date');
        $endDate = $this->schemaText($row, $schema, 'end_date');
        $noteParts = [];

        if ($oldTime !== '') {
            $noteParts[] = 'Thời gian cũ: ' . $oldTime;
        }

        if ($startDate !== '' || $endDate !== '') {
            $noteParts[] = 'Thời gian học: ' . trim($startDate . ' - ' . $endDate, ' -');
        }

        if ($group !== '') {
            $noteParts[] = 'Nhóm: ' . $group;
        }

        $sectionData = [
            'subject_id' => $subject->id,
            'program_id' => $program?->id,
            'cohort_id' => $cohort?->id,
            'note' => $noteParts ? implode("\n", $noteParts) : null,
        ];

        $this->putIfNotNull($sectionData, 'max_students', $this->schemaInt($row, $schema, 'max_students'));
        $this->putIfNotEmpty($sectionData, 'teaching_mode', $this->schemaText($row, $schema, 'teaching_mode'));
        $this->putIfNotEmpty($sectionData, 'teaching_language', $this->schemaText($row, $schema, 'teaching_language'));
        $this->putIfNotEmpty($sectionData, 'grading_owner', $this->schemaText($row, $schema, 'grading_owner'));
        $this->putIfNotEmpty($sectionData, 'support_request', $this->schemaText($row, $schema, 'support_request'));

        $section = Section::updateOrCreate(
            ['section_code' => $sectionCode],
            $sectionData
        );

        $lecturerName = $this->schemaText($row, $schema, 'lecturer_name');
        if ($lecturerName !== '') {
            $state['lecturer_name'] = $lecturerName;
        } else {
            $lecturerName = $state['lecturer_name'] ?? '';
        }

        $department = $this->schemaText($row, $schema, 'department');
        if ($department !== '') {
            $state['department'] = $department;
        } else {
            $department = $state['department'] ?? '';
        }

        $email = $this->schemaText($row, $schema, 'email');
        if ($email !== '') {
            $state['email'] = $email;
        } else {
            $email = $state['email'] ?? '';
        }

        $phone = $this->schemaText($row, $schema, 'phone');
        if ($phone !== '') {
            $state['phone'] = $phone;
        } else {
            $phone = $state['phone'] ?? '';
        }

        if ($this->isValidLecturerName($lecturerName)) {
            $lecturer = Lecturer::firstOrCreate(
                [
                    'name' => $lecturerName,
                    'email' => $email ?: null,
                ],
                [
                    'department' => $department ?: null,
                    'phone' => $phone ?: null,
                ]
            );

            SectionInstructor::updateOrCreate(
                [
                    'section_id' => $section->id,
                    'lecturer_id' => $lecturer->id,
                ],
                [
                    'theory_hours' => $this->schemaInt($row, $schema, 'theory_hours'),
                    'practice_hours' => $this->schemaInt($row, $schema, 'practice_hours'),
                    'self_study_hours' => $this->schemaInt($row, $schema, 'self_study_hours'),
                    'role' => 'Giảng viên phụ trách',
                ]
            );
        }

        $roomName = $this->schemaText($row, $schema, 'room_name');
        if ($roomName !== '') {
            $state['room_name'] = $roomName;
        } else {
            $roomName = $state['room_name'] ?? '';
        }

        $room = null;
        if ($roomName !== '') {
            $room = Room::firstOrCreate(
                ['name' => $roomName],
                [
                    'type' => $this->guessRoomType($roomName),
                    'campus' => $this->guessCampus($roomName),
                ]
            );
        }

        $meetings = $this->normalizeMeetings($this->parseDynamicMeetings($row, $schema));
        foreach ($meetings as $meeting) {
            SectionMeeting::updateOrCreate(
                [
                    'section_id' => $section->id,
                    'day_of_week' => $meeting['day_of_week'],
                    'start_period' => $meeting['start_period'],
                    'end_period' => $meeting['end_period'],
                ],
                [
                    'room_id' => $room?->id,
                    'note' => ($startDate !== '' || $endDate !== '')
                        ? 'Thời gian học: ' . trim($startDate . ' - ' . $endDate, ' -')
                        : null,
                ]
            );
        }

        return true;
    }

    private function parseDynamicMeetings(array $row, array $schema): array
    {
        $dayRaw = $this->schemaText($row, $schema, 'day');
        $startRaw = $this->schemaText($row, $schema, 'start_period');
        $endRaw = $this->schemaText($row, $schema, 'end_period');
        $periodRaw = $this->schemaText($row, $schema, 'period_range');
        $timeRaw = $this->schemaText($row, $schema, 'time_raw');

        if ($dayRaw !== '' && $startRaw !== '' && $endRaw !== '') {
            return $this->parseMeetings($dayRaw, $startRaw, $endRaw);
        }

        if ($dayRaw !== '' && $periodRaw !== '') {
            return $this->parseSchoolwideMeetings($dayRaw, $periodRaw);
        }

        if ($timeRaw !== '') {
            return $this->parseCombinedTimeMeetings($timeRaw);
        }

        return [];
    }

    private function parseCombinedTimeMeetings(string $timeRaw): array
    {
        $meetings = [];
        $lines = preg_split('/\n+/', $timeRaw) ?: [];

        foreach ($lines as $line) {
            $line = $this->cleanText($line);
            if ($line === '') {
                continue;
            }

            $normalized = $this->normalizeHeader($line);
            $days = [];

            if (preg_match_all('/\b(?:thứ|thu|th)\s*([2-8])\b/iu', $line, $matches)) {
                $days = array_map('intval', $matches[1]);
            } elseif (preg_match_all('/(?:^|[\s,;])t([2-8])\b/iu', $line, $matches)) {
                $days = array_map('intval', $matches[1]);
            } elseif (preg_match_all('/\b(?:thu|th)\s*([2-8])\b/u', $normalized, $matches)) {
                $days = array_map('intval', $matches[1]);
            }

            preg_match_all('/\d+/', $normalized, $numberMatches);
            $numbers = array_map('intval', $numberMatches[0] ?? []);

            if (empty($days)) {
                if (count($numbers) >= 3 && $numbers[0] >= 2 && $numbers[0] <= 8) {
                    $days = [$numbers[0]];
                }
            }

            $start = null;
            $end = null;

            if (
                preg_match('/(\d+)\s*[-–—]\s*(\d+)/u', $line, $periodMatch)
                || preg_match('/(\d+)\s*[-–—]\s*(\d+)/u', $normalized, $periodMatch)
            ) {
                $start = (int) $periodMatch[1];
                $end = (int) $periodMatch[2];
            } elseif (!empty($days)) {
                $periodNumbers = $numbers;

                if ($periodNumbers && in_array($periodNumbers[0], $days, true)) {
                    array_shift($periodNumbers);
                }

                if (count($periodNumbers) >= 2) {
                    $start = (int) $periodNumbers[0];
                    $end = (int) $periodNumbers[1];
                }
            }

            if ($start === null || $end === null) {
                continue;
            }

            foreach (array_unique($days) as $day) {
                $meetings[] = [
                    'day_of_week' => $day,
                    'start_period' => $start,
                    'end_period' => $end,
                ];
            }
        }

        return $meetings;
    }

    private function normalizeMeetings(array $meetings): array
    {
        $normalized = [];

        foreach ($meetings as $meeting) {
            $start = (int) ($meeting['start_period'] ?? 0);
            $end = (int) ($meeting['end_period'] ?? 0);

            if ($start <= 0 || $end <= 0) {
                continue;
            }

            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            if ($start > self::MAX_PERIOD_PER_DAY) {
                continue;
            }

            $meeting['start_period'] = $start;
            $meeting['end_period'] = min($end, self::MAX_PERIOD_PER_DAY);

            $normalized[] = $meeting;
        }

        return $normalized;
    }

    private function schemaText(array $row, array $schema, string $field): string
    {
        $column = $schema['columns'][$field] ?? null;

        if (!$column) {
            return '';
        }

        return $this->cleanText($row[$column] ?? '');
    }

    private function schemaInt(array $row, array $schema, string $field): ?int
    {
        $column = $schema['columns'][$field] ?? null;

        if (!$column) {
            return null;
        }

        return $this->toInt($row[$column] ?? null);
    }

    private function putIfNotEmpty(array &$data, string $key, string $value): void
    {
        if ($value !== '') {
            $data[$key] = $value;
        }
    }

    private function putIfNotNull(array &$data, string $key, ?int $value): void
    {
        if ($value !== null) {
            $data[$key] = $value;
        }
    }

    private function detectDynamicSchema($worksheet): ?array
    {
        $rows = $worksheet->toArray(null, true, true, true);
        $maxRow = min(count($rows), 80);
        $best = null;

        for ($rowIndex = 1; $rowIndex <= $maxRow; $rowIndex++) {
            foreach ([1, 2] as $height) {
                if ($height === 2 && !isset($rows[$rowIndex + 1])) {
                    continue;
                }

                $headerRows = [$rows[$rowIndex] ?? []];
                if ($height === 2) {
                    $headerRows[] = $rows[$rowIndex + 1] ?? [];
                }

                $columns = $this->mapDynamicColumns($headerRows);
                $score = $this->scoreDynamicColumns($columns);
                $fieldCount = count($columns);

                if ($score === 0) {
                    continue;
                }

                if (
                    !$best
                    || $score > $best['score']
                    || ($score === $best['score'] && $fieldCount > $best['field_count'])
                    || ($score === $best['score'] && $fieldCount === $best['field_count'] && $rowIndex > $best['header_row'])
                ) {
                    $best = [
                        'header_row' => $rowIndex,
                        'data_row' => $rowIndex + $height,
                        'columns' => $columns,
                        'score' => $score,
                        'field_count' => $fieldCount,
                    ];
                }
            }
        }

        return $best && $best['score'] >= 7 ? $best : null;
    }

    private function mapDynamicColumns(array $headerRows): array
    {
        $columns = [];
        $allColumnKeys = [];

        foreach ($headerRows as $row) {
            $allColumnKeys = array_unique(array_merge($allColumnKeys, array_keys($row)));
        }

        foreach ($allColumnKeys as $column) {
            $parts = [];

            foreach ($headerRows as $row) {
                $text = $this->cleanText($row[$column] ?? '');
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            if (empty($parts)) {
                continue;
            }

            $field = $this->resolveHeaderField(implode(' ', $parts));
            if ($field && !isset($columns[$field])) {
                $columns[$field] = $column;
            }
        }

        return $columns;
    }

    private function scoreDynamicColumns(array $columns): int
    {
        if (!isset($columns['section_code'])) {
            return 0;
        }

        if (!isset($columns['subject_name']) && !isset($columns['subject_code'])) {
            return 0;
        }

        $score = 4;

        foreach (['subject_name', 'subject_code', 'lecturer_name', 'room_name', 'credits'] as $field) {
            if (isset($columns[$field])) {
                $score += 2;
            }
        }

        $hasSplitTime = isset($columns['day'], $columns['start_period'], $columns['end_period']);
        $hasRangeTime = isset($columns['day'], $columns['period_range']);
        $hasCombinedTime = isset($columns['time_raw']);

        if ($hasSplitTime || $hasRangeTime || $hasCombinedTime) {
            $score += 4;
        }

        foreach (['program_code', 'cohort_name', 'max_students', 'teaching_mode'] as $field) {
            if (isset($columns[$field])) {
                $score++;
            }
        }

        return $score;
    }

    private function resolveHeaderField(string $header): ?string
    {
        $header = $this->normalizeHeader($header);

        if ($this->headerContainsAny($header, ['ma lop hoc phan', 'ma lhp', 'ma lop hp'])) {
            return 'section_code';
        }

        if ($this->headerContainsAny($header, ['ho ten gv', 'ho ten giang vien', 'ten giang vien', 'giang vien', 'gv phu trach'])) {
            return 'lecturer_name';
        }

        if ($this->headerContainsAny($header, ['ma hoc phan', 'ma mon hoc', 'ma mon', 'ma lop hoc'])) {
            return 'subject_code';
        }

        if ($this->headerContainsAny($header, ['ten hoc phan', 'ten mon hoc']) || $this->headerIsAny($header, ['hoc phan', 'mon hoc'])) {
            return 'subject_name';
        }

        if ($this->headerContainsAny($header, ['so tin chi', 'so tc', 'tin chi'])) {
            return 'credits';
        }

        if ($this->headerContainsAny($header, ['so gio']) && $this->headerContainsAny($header, ['ly thuyet'])) {
            return 'theory_hours';
        }

        if ($this->headerContainsAny($header, ['so gio']) && $this->headerContainsAny($header, ['thuc hanh'])) {
            return 'practice_hours';
        }

        if ($this->headerContainsAny($header, ['so gio']) && $this->headerContainsAny($header, ['tu hoc'])) {
            return 'self_study_hours';
        }

        if ($this->headerContainsAny($header, ['ly thuyet'])) {
            return 'theory_credits';
        }

        if ($this->headerContainsAny($header, ['thuc hanh'])) {
            return 'practice_credits';
        }

        if ($this->headerContainsAny($header, ['tu hoc'])) {
            return 'self_study_credits';
        }

        if ($this->headerContainsAny($header, ['tiet dau', 'tiet bat dau'])) {
            return 'start_period';
        }

        if ($this->headerContainsAny($header, ['tiet cuoi', 'tiet ket thuc'])) {
            return 'end_period';
        }

        if ($this->headerContainsAny($header, ['ngay bat dau']) || ($this->headerContainsAny($header, ['bat dau']) && !$this->headerContainsAny($header, ['tiet']))) {
            return 'start_date';
        }

        if ($this->headerContainsAny($header, ['ngay ket thuc']) || ($this->headerContainsAny($header, ['ket thuc']) && !$this->headerContainsAny($header, ['tiet']))) {
            return 'end_date';
        }

        if ($this->headerIsAny($header, ['thu']) || $this->headerContainsAny($header, ['lich hoc thu', 'thoi gian thu', 'ngay hoc'])) {
            return 'day';
        }

        if ($this->headerIsAny($header, ['tiet']) || $this->headerContainsAny($header, ['lich hoc tiet'])) {
            return 'period_range';
        }

        if ($this->headerContainsAny($header, ['ten phong', 'phong hoc', 'dia diem', 'dia diem giang day'])) {
            return 'room_name';
        }

        if ($this->headerIsAny($header, ['phong'])) {
            return 'room_name';
        }

        if ($this->headerContainsAny($header, ['don vi cong tac', 'don vi'])) {
            return 'department';
        }

        if ($this->headerContainsAny($header, ['email', 'e mail'])) {
            return 'email';
        }

        if ($this->headerContainsAny($header, ['so dien thoai', 'dien thoai', 'phone'])) {
            return 'phone';
        }

        if ($this->headerContainsAny($header, ['ctdt', 'chuong trinh dao tao'])) {
            return 'program_code';
        }

        if ($this->headerIsAny($header, ['khoa']) || $this->headerContainsAny($header, ['khoa lop', 'khoa hoc'])) {
            return 'cohort_name';
        }

        if ($this->headerContainsAny($header, ['so sv toi da', 'si so', 'quy mo'])) {
            return 'max_students';
        }

        if ($this->headerContainsAny($header, ['thoi gian nam ngoai', 'nam ngoai'])) {
            return 'old_time';
        }

        if ($this->headerContainsAny($header, ['hinh thuc giang day', 'hinh thuc'])) {
            return 'teaching_mode';
        }

        if ($this->headerContainsAny($header, ['ngon ngu giang day', 'ngon ngu'])) {
            return 'teaching_language';
        }

        if ($this->headerContainsAny($header, ['de xuat ho tro', 'ho tro'])) {
            return 'support_request';
        }

        if ($this->headerContainsAny($header, ['phu trach nhap diem', 'nhap diem'])) {
            return 'grading_owner';
        }

        if ($this->headerIsAny($header, ['nhom'])) {
            return 'group';
        }

        if ($this->headerContainsAny($header, ['thoi gian hoc', 'lich hoc', 'thoi khoa bieu', 'thoi gian'])) {
            return 'time_raw';
        }

        return null;
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower($this->cleanText($value), 'UTF-8');
        $value = strtr($value, [
            'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
            'đ' => 'd',
        ]);

        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function headerContainsAny(string $header, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($header, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function headerIsAny(string $header, array $values): bool
    {
        foreach ($values as $value) {
            if ($header === $value) {
                return true;
            }
        }

        return false;
    }

    private function resetImportedData(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            ScheduleConflict::truncate();
            SectionInstructor::truncate();
            SectionMeeting::truncate();
            Section::truncate();

            Subject::truncate();
            Program::truncate();
            Cohort::truncate();

            ImportBatch::truncate();
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
    private function findTimetableSheet(Spreadsheet $spreadsheet)
    {
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            if ($this->detectDynamicSchema($worksheet)) {
                return $worksheet;
            }

            if ($this->isSchoolwideTimetableSheet($worksheet)) {
                return $worksheet;
            }

            $rows = $worksheet->rangeToArray('A1:Z12', null, true, true, true);

            $text = '';

            foreach ($rows as $row) {
                foreach ($row as $cell) {
                    $text .= ' ' . mb_strtolower(trim((string) $cell), 'UTF-8');
                }
            }

            $score = 0;

            if (str_contains($text, 'mã học phần')) {
                $score++;
            }

            if (str_contains($text, 'tên học phần')) {
                $score++;
            }

            if (str_contains($text, 'mã lớp học phần')) {
                $score++;
            }

            if (str_contains($text, 'số tín chỉ')) {
                $score++;
            }

            if (str_contains($text, 'họ tên gv') || str_contains($text, 'họ tên giảng viên')) {
                $score++;
            }

            if (str_contains($text, 'tiết đầu') && str_contains($text, 'tiết cuối')) {
                $score++;
            }

            if (str_contains($text, 'lịch toàn trường') || str_contains($text, 'lich toan truong')) {
                $score += 2;
            }

            if (str_contains($text, 'tên môn học')) {
                $score++;
            }

            if (str_contains($text, 'lịch học') && str_contains($text, 'thời gian học')) {
                $score++;
            }

            if ($score >= 3) {
                return $worksheet;
            }
        }

        return null;
    }

    private function isSchoolwideTimetableSheet($worksheet): bool
    {
        if (str_contains($worksheet->getTitle(), 'DanhSachLichToanTruong')) {
            return true;
        }

        $rows = $worksheet->rangeToArray('A1:L12', null, true, true, true);
        $text = '';

        foreach ($rows as $row) {
            foreach ($row as $cell) {
                $text .= ' ' . mb_strtolower(trim((string) $cell), 'UTF-8');
            }
        }

        return (str_contains($text, 'lịch toàn trường') || str_contains($text, 'lich toan truong'))
            && str_contains($text, 'mã lớp học phần')
            && str_contains($text, 'tên môn học')
            && str_contains($text, 'lịch học');
    }
}
