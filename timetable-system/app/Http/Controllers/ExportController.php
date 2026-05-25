<?php

namespace App\Http\Controllers;

use App\Models\Section;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    private const MAX_PERIOD_PER_DAY = 12;

    public function timetable(): StreamedResponse
    {
        $sections = Section::query()
            ->with([
                'subject',
                'program',
                'cohort',
                'instructors.lecturer',
                'lecturers',
                'meetings.room',
            ])
            ->orderBy('section_code')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Thời khóa biểu');

        $this->setupPage($sheet);
        $this->buildHeader($sheet);
        $this->buildTableHeader($sheet);

        $startRow = 8;
        $row = $startRow;
        $index = 1;

        foreach ($sections as $section) {
            $subject = $section->subject;

            $sheet->setCellValue("A{$row}", $index);
            $sheet->setCellValue("B{$row}", $subject?->subject_code ?? '');
            $sheet->setCellValue("C{$row}", $subject?->name ?? '');
            $sheet->setCellValue("D{$row}", $subject?->credits ?? '');
            $sheet->setCellValue("E{$row}", $section->section_code);

            $sheet->setCellValue("F{$row}", $subject?->theory_credits ?? '');
            $sheet->setCellValue("G{$row}", $subject?->practice_credits ?? '');
            $sheet->setCellValue("H{$row}", $subject?->self_study_credits ?? '');

            $sheet->setCellValue("I{$row}", $section->cohort?->name ?? '');
            $sheet->setCellValue("J{$row}", $section->program?->code ?? '');
            $sheet->setCellValue("K{$row}", $section->max_students ?? '');

            $sheet->setCellValue("L{$row}", $this->formatMeetings($section));
            $sheet->setCellValue("M{$row}", $this->formatLecturers($section));

            $sheet->setCellValue("N{$row}", $section->instructors->sum('theory_hours') ?: '');
            $sheet->setCellValue("O{$row}", $section->instructors->sum('practice_hours') ?: '');
            $sheet->setCellValue("P{$row}", $section->instructors->sum('self_study_hours') ?: '');

            $sheet->setCellValue("Q{$row}", $this->formatRooms($section));
            $sheet->setCellValue("R{$row}", $section->teaching_mode ?? '');
            $sheet->setCellValue("S{$row}", $section->support_request ?? '');
            $sheet->setCellValue("T{$row}", $section->grading_owner ?? '');

            $row++;
            $index++;
        }

        $lastDataRow = max($row - 1, $startRow);

        $this->styleTableBody($sheet, $startRow, $lastDataRow);
        $this->buildFooter($sheet, $lastDataRow + 2, $sections->count());
        $this->setColumnWidths($sheet);

        $fileName = 'thoi-khoa-bieu-' . now()->format('Ymd-His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function setupPage($sheet): void
    {
        $sheet->getPageSetup()
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $sheet->getPageMargins()->setTop(0.4);
        $sheet->getPageMargins()->setRight(0.25);
        $sheet->getPageMargins()->setLeft(0.25);
        $sheet->getPageMargins()->setBottom(0.4);
    }

    private function buildHeader($sheet): void
    {
        $sheet->mergeCells('A1:F1');
        $sheet->setCellValue('A1', 'TRƯỜNG ĐẠI HỌC VIỆT NHẬT');
        $sheet->mergeCells('A2:F2');
        $sheet->setCellValue('A2', 'KHOA/VIỆN: ................................');

        $sheet->mergeCells('G1:T1');
        $sheet->setCellValue('G1', 'CỘNG HÒA XÃ HỘI CHỦ NGHĨA VIỆT NAM');
        $sheet->mergeCells('G2:T2');
        $sheet->setCellValue('G2', 'Độc lập - Tự do - Hạnh phúc');

        $sheet->mergeCells('A4:T4');
        $sheet->setCellValue('A4', 'THỜI KHÓA BIỂU HỌC KỲ ..... NĂM HỌC 20... - 20...');

        $sheet->mergeCells('A5:T5');
        $sheet->setCellValue('A5', 'Thời gian giảng dạy: từ ..... đến .....');

        $sheet->getStyle('A1:T5')->getFont()->setName('Times New Roman');
        $sheet->getStyle('A1:T5')->getFont()->setBold(true);
        $sheet->getStyle('A1:T5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A1:T5')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle('A4')->getFont()->setSize(15);
    }

    private function buildTableHeader($sheet): void
    {
        $sheet->setCellValue('A6', 'TT');
        $sheet->setCellValue('B6', 'Mã học phần');
        $sheet->setCellValue('C6', 'Tên học phần');
        $sheet->setCellValue('D6', 'Số tín chỉ');
        $sheet->setCellValue('E6', 'Mã lớp học phần');

        $sheet->setCellValue('F6', 'Phân bổ TC');
        $sheet->mergeCells('F6:H6');
        $sheet->setCellValue('F7', 'Lý thuyết');
        $sheet->setCellValue('G7', 'Thực hành');
        $sheet->setCellValue('H7', 'Tự học');

        $sheet->setCellValue('I6', 'Khóa');
        $sheet->setCellValue('J6', 'CTĐT');
        $sheet->setCellValue('K6', 'Số sinh viên theo lớp HP');
        $sheet->setCellValue('L6', 'Thời gian giảng dạy (Thứ, Tiết)');
        $sheet->setCellValue('M6', 'Giảng viên phụ trách');

        $sheet->setCellValue('N6', 'Số giờ dạy');
        $sheet->mergeCells('N6:P6');
        $sheet->setCellValue('N7', 'Lý thuyết');
        $sheet->setCellValue('O7', 'Thực hành');
        $sheet->setCellValue('P7', 'Tự học');

        $sheet->setCellValue('Q6', 'Địa điểm giảng dạy');
        $sheet->setCellValue('R6', 'Hình thức giảng dạy');
        $sheet->setCellValue('S6', 'Đề xuất hỗ trợ');
        $sheet->setCellValue('T6', 'Phụ trách nhập điểm');

        $singleHeaderColumns = ['A', 'B', 'C', 'D', 'E', 'I', 'J', 'K', 'L', 'M', 'Q', 'R', 'S', 'T'];

        foreach ($singleHeaderColumns as $column) {
            $sheet->mergeCells("{$column}6:{$column}7");
        }

        $sheet->getStyle('A6:T7')->getFont()->setName('Times New Roman');
        $sheet->getStyle('A6:T7')->getFont()->setBold(true);
        $sheet->getStyle('A6:T7')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('A6:T7')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A6:T7')->getAlignment()->setWrapText(true);

        $sheet->getStyle('A6:T7')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFEFEFEF');

        $sheet->getStyle('A6:T7')->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $sheet->getRowDimension(6)->setRowHeight(30);
        $sheet->getRowDimension(7)->setRowHeight(48);
    }

    private function styleTableBody($sheet, int $startRow, int $lastRow): void
    {
        if ($lastRow < $startRow) {
            return;
        }

        $range = "A{$startRow}:T{$lastRow}";

        $sheet->getStyle($range)->getFont()->setName('Times New Roman');
        $sheet->getStyle($range)->getFont()->setSize(11);

        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true);

        $sheet->getStyle("A{$startRow}:B{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("D{$startRow}:K{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("N{$startRow}:P{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        for ($r = $startRow; $r <= $lastRow; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(45);
        }

        $sheet->setAutoFilter("A7:T{$lastRow}");
        $sheet->freezePane('A8');
    }

    private function buildFooter($sheet, int $footerRow, int $totalSections): void
    {
        $sheet->mergeCells("A{$footerRow}:F{$footerRow}");
        $sheet->setCellValue("A{$footerRow}", "Danh sách gồm {$totalSections} lớp học phần.");

        $sheet->mergeCells("A" . ($footerRow + 2) . ":F" . ($footerRow + 2));
        $sheet->setCellValue("A" . ($footerRow + 2), 'Người lập bảng');

        $sheet->mergeCells("A" . ($footerRow + 3) . ":F" . ($footerRow + 3));
        $sheet->setCellValue("A" . ($footerRow + 3), '(ký và ghi rõ họ tên)');

        $sheet->mergeCells("N" . ($footerRow + 1) . ":T" . ($footerRow + 1));
        $sheet->setCellValue("N" . ($footerRow + 1), 'Hà Nội, ngày ..... tháng ..... năm 20...');

        $sheet->mergeCells("N" . ($footerRow + 2) . ":T" . ($footerRow + 2));
        $sheet->setCellValue("N" . ($footerRow + 2), 'Đại diện Khoa/Viện');

        $sheet->mergeCells("N" . ($footerRow + 3) . ":T" . ($footerRow + 3));
        $sheet->setCellValue("N" . ($footerRow + 3), '(ký và ghi rõ họ tên)');

        $sheet->getStyle("A{$footerRow}:T" . ($footerRow + 3))
            ->getFont()
            ->setName('Times New Roman');

        $sheet->getStyle("A{$footerRow}:T" . ($footerRow + 3))
            ->getAlignment()
            ->setWrapText(true);

        $sheet->getStyle("A" . ($footerRow + 2) . ":T" . ($footerRow + 3))
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("A{$footerRow}:A{$footerRow}")
            ->getFont()
            ->setItalic(true);
    }

    private function setColumnWidths($sheet): void
    {
        $widths = [
            'A' => 6,
            'B' => 14,
            'C' => 30,
            'D' => 9,
            'E' => 16,
            'F' => 11,
            'G' => 11,
            'H' => 11,
            'I' => 12,
            'J' => 14,
            'K' => 13,
            'L' => 24,
            'M' => 30,
            'N' => 11,
            'O' => 11,
            'P' => 11,
            'Q' => 18,
            'R' => 18,
            'S' => 28,
            'T' => 20,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function formatMeetings(Section $section): string
    {
        if ($section->meetings->isEmpty()) {
            return '';
        }

        return $section->meetings
            ->sortBy([
                ['day_of_week', 'asc'],
                ['start_period', 'asc'],
            ])
            ->map(function ($meeting) {
                return 'Thứ ' . $meeting->day_of_week
                    . ', tiết ' . $meeting->start_period
                    . '-' . min($meeting->end_period, self::MAX_PERIOD_PER_DAY);
            })
            ->join("\n");
    }

    private function formatLecturers(Section $section): string
    {
        if ($section->lecturers->isEmpty()) {
            return '';
        }

        return $section->lecturers
            ->pluck('name')
            ->unique()
            ->values()
            ->join("\n");
    }

    private function formatRooms(Section $section): string
    {
        $rooms = $section->meetings
            ->map(fn ($meeting) => $meeting->room?->name)
            ->filter()
            ->unique()
            ->values();

        if ($rooms->isEmpty()) {
            return '';
        }

        return $rooms->join("\n");
    }
}
