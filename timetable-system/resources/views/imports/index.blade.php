@extends('layouts.app')

@section('title', 'Nhập & xếp lịch')
@section('page_title', 'Nhập & xếp lịch')

@php
    $dayLabel = fn ($day) => 'Thứ ' . $day;
    $roomLabel = fn ($room) => match ($room?->type) {
        'online' => 'Online',
        'lab' => 'Lab',
        default => 'Phòng',
    };
@endphp

@section('content')
    <section class="schedule-workspace">
        <div class="import-panel">
            <div>
                <div class="panel-kicker">Dữ liệu đầu vào</div>
                <h2>Import file Excel</h2>
                <p>
                    Hệ thống đọc mã học phần, tên môn học và giảng viên từ file lịch toàn trường.
                    Phòng học được lấy từ danh sách phòng đã nạp sẵn, còn lịch học sẽ được tạo ở bước xếp lịch.
                </p>
            </div>

            <form class="inline-import" action="{{ route('imports.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <label>
                    <span>File Excel đầu vào</span>
                    <input type="file" name="excel_file" accept=".xlsx,.xls" required>
                </label>
                <button class="btn" type="submit">Import dữ liệu</button>
            </form>
        </div>

        <div class="schedule-tools">
            <div class="tool-card">
                <span>Học phần đã import</span>
                <strong>{{ $dataQuality['total_sections'] }}</strong>
                <small>{{ $dataQuality['sections_with_lecturers'] }} môn đã có giảng viên</small>
            </div>

            <div class="tool-card {{ $dataQuality['sections_missing_valid_lecturers'] > 0 ? 'warning-tool' : '' }}">
                <span>Cần bổ sung</span>
                <strong>{{ $dataQuality['sections_missing_valid_lecturers'] }}</strong>
                <small>Môn chưa gắn giảng viên hợp lệ</small>
            </div>

            <form class="tool-card action-tool" action="{{ route('schedule.generate') }}" method="POST">
                @csrf
                <span>Xử lý lịch</span>
                <strong>Xếp lịch</strong>
                <small>Dựa trên ngày/buổi giảng viên có thể dạy và danh sách phòng học.</small>
                <button class="btn btn-green" type="submit">Tạo thời khóa biểu</button>
            </form>
        </div>
    </section>

    <section class="note">
        Trước khi bấm xếp lịch, vào trang <strong>Môn học</strong> để chọn ngày và buổi có thể dạy cho giảng viên của từng môn.
        Với timeline IS, buổi sáng là tiết 1-6 và buổi chiều là tiết 7-12.
    </section>

    <section class="schedule-panel">
        <div class="section-head">
            <div>
                <h2>Bảng thời khóa biểu</h2>
                <p>
                    Lịch được hiển thị theo thứ và số tiết, không quy đổi sang giờ bắt đầu/kết thúc.
                    @if($selectedCampus !== 'all')
                        Đang lọc theo cơ sở {{ $campuses[$selectedCampus] ?? $selectedCampus }}.
                    @endif
                </p>
            </div>

            <form class="timetable-campus-filter" method="GET" action="{{ route('imports.index') }}">
                @if($studyMode !== 'all')
                    <input type="hidden" name="study_mode" value="{{ $studyMode }}">
                @endif

                <label>
                    <span>Cơ sở</span>
                    <select name="campus" onchange="this.form.submit()">
                        <option value="all" @selected($selectedCampus === 'all')>Tất cả cơ sở</option>
                        @foreach($campuses as $value => $label)
                            <option value="{{ $value }}" @selected($selectedCampus === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </form>

            <a class="btn export-btn" href="{{ route('timetable.export', ['campus' => $selectedCampus, 'study_mode' => $studyMode]) }}">
                Lưu TKB
            </a>
        </div>

        <table class="timetable-table">
            <thead>
            <tr>
                <th class="period-cell">Tiết</th>
                @foreach(range(2, 7) as $day)
                    <th>{{ $dayLabel($day) }}</th>
                @endforeach
            </tr>
            </thead>

            <tbody>
            @foreach($periods as $period)
                <tr>
                    <td class="period-cell">Tiết {{ $period }}</td>

                    @foreach(range(2, 7) as $day)
                        <td>
                            @php
                                $cellMeetings = $grid[$period][$day] ?? [];
                            @endphp

                            @forelse($cellMeetings as $meeting)
                                @php
                                    $isStart = $meeting->start_period == $period;
                                    $displayEndPeriod = min($meeting->end_period, $maxPeriod);
                                    $hasConflict = in_array($meeting->id, $conflictMeetingIds, true);
                                @endphp

                                @if($isStart)
                                    <div class="meeting-card {{ $hasConflict ? 'conflict' : '' }}">
                                        <div class="section-code">{{ $meeting->section?->section_code }}</div>
                                        <div class="subject-name">{{ $meeting->section?->subject?->name ?? 'Không rõ môn học' }}</div>
                                        <div>Tiết {{ $meeting->start_period }}-{{ $displayEndPeriod }}</div>

                                        @if($meeting->section && $meeting->section->lecturers->count() > 0)
                                            <div class="lecturer">
                                                GV: {{ $meeting->section->lecturers->pluck('name')->join(', ') }}
                                            </div>
                                        @endif

                                        @if($meeting->room)
                                            <div class="room">
                                                {{ $roomLabel($meeting->room) }}: {{ $meeting->room->name }}
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <div class="meeting-card continuing {{ $hasConflict ? 'conflict' : '' }}">
                                        {{ $meeting->section?->section_code }} tiếp tục
                                    </div>
                                @endif
                            @empty
                                <div class="timetable-empty">-</div>
                            @endforelse
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endsection
