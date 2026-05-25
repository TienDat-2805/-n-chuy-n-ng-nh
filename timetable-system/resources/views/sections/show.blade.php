@extends('layouts.app')

@section('title', 'Chi tiết lớp học phần')
@section('page_title', 'Chi tiết lớp học phần: ' . $section->section_code)
@section('container_class', 'container-sm')

@section('content')
    <a class="btn" href="{{ route('sections.index') }}">← Quay lại danh sách</a>

    <div class="section-card">
        <div class="info-grid">
            <div class="label">Mã học phần</div>
            <div>{{ $section->subject?->subject_code ?? '—' }}</div>

            <div class="label">Tên học phần</div>
            <div>{{ $section->subject?->name ?? '—' }}</div>

            <div class="label">Số tín chỉ</div>
            <div>{{ $section->subject?->credits ?? '—' }}</div>

            <div class="label">CTĐT</div>
            <div>{{ $section->program?->code ?? '—' }}</div>

            <div class="label">Khóa</div>
            <div>{{ $section->cohort?->name ?? '—' }}</div>

            <div class="label">Số SV tối đa</div>
            <div>{{ $section->max_students ?? '—' }}</div>

            <div class="label">Hình thức giảng dạy</div>
            <div>{{ $section->teaching_mode ?? '—' }}</div>

            <div class="label">Ngôn ngữ giảng dạy</div>
            <div>{{ $section->teaching_language ?? '—' }}</div>

            <div class="label">Đề xuất hỗ trợ</div>
            <div>{{ $section->support_request ?? '—' }}</div>

            <div class="label">Phụ trách nhập điểm</div>
            <div>{{ $section->grading_owner ?? '—' }}</div>

            <div class="label">Ghi chú</div>
            <div>{{ $section->note ?? '—' }}</div>
        </div>
    </div>

    <h2>Giảng viên</h2>

    <table>
        <thead>
        <tr>
            <th>Họ tên</th>
            <th>Đơn vị</th>
            <th>Email</th>
            <th>SĐT</th>
            <th>Giờ LT</th>
            <th>Giờ TH</th>
            <th>Giờ tự học</th>
        </tr>
        </thead>
        <tbody>
        @forelse($section->instructors as $instructor)
            <tr>
                <td>{{ $instructor->lecturer?->name ?? '—' }}</td>
                <td>{{ $instructor->lecturer?->department ?? '—' }}</td>
                <td>{{ $instructor->lecturer?->email ?? '—' }}</td>
                <td>{{ $instructor->lecturer?->phone ?? '—' }}</td>
                <td>{{ $instructor->theory_hours ?? '—' }}</td>
                <td>{{ $instructor->practice_hours ?? '—' }}</td>
                <td>{{ $instructor->self_study_hours ?? '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="empty">Chưa có giảng viên.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <h2>Lịch học</h2>

    <table>
        <thead>
        <tr>
            <th>Thứ</th>
            <th>Tiết đầu</th>
            <th>Tiết cuối</th>
            <th>Phòng/Địa điểm</th>
        </tr>
        </thead>
        <tbody>
        @forelse($section->meetings as $meeting)
            <tr>
                <td>Thứ {{ $meeting->day_of_week }}</td>
                <td>{{ $meeting->start_period }}</td>
                <td>{{ min($meeting->end_period, 12) }}</td>
                <td>{{ $meeting->room?->name ?? '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="empty">Chưa có lịch học.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
@endsection
