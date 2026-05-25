@extends('layouts.app')

@section('title', 'Thời khóa biểu')
@section('page_title', 'Thời khóa biểu')

@section('content')
    <form class="filters" method="GET" action="{{ route('timetable.index') }}">
        <select name="section_id">
            <option value="">-- Lọc theo lớp học phần --</option>
            @foreach($sections as $section)
                <option value="{{ $section->id }}" @selected($selectedSectionId == $section->id)>
                    {{ $section->section_code }}
                    @if($section->subject)
                        - {{ $section->subject->name }}
                    @endif
                </option>
            @endforeach
        </select>

        <select name="lecturer_id">
            <option value="">-- Lọc theo giảng viên --</option>
            @foreach($lecturers as $lecturer)
                <option value="{{ $lecturer->id }}" @selected($selectedLecturerId == $lecturer->id)>
                    {{ $lecturer->name }}
                </option>
            @endforeach
        </select>

        <select name="room_id">
            <option value="">-- Lọc theo phòng/địa điểm --</option>
            @foreach($rooms as $room)
                <option value="{{ $room->id }}" @selected($selectedRoomId == $room->id)>
                    {{ $room->name }}
                </option>
            @endforeach
        </select>

        <button class="btn btn-green" type="submit">Lọc lịch</button>

        <a class="btn btn-gray" href="{{ route('timetable.index') }}">Làm mới</a>
    </form>

    <div class="summary">
        Tổng số lịch học đang hiển thị: <strong>{{ $meetings->count() }}</strong>
    </div>

    <table class="timetable-table">
        <thead>
        <tr>
            <th class="period-cell">Tiết</th>
            <th>Thứ 2</th>
            <th>Thứ 3</th>
            <th>Thứ 4</th>
            <th>Thứ 5</th>
            <th>Thứ 6</th>
        </tr>
        </thead>

        <tbody>
        @foreach($periods as $period)
            <tr>
                <td class="period-cell">
                    Tiết {{ $period }}

                </td>

                @for($day = 2; $day <= 6; $day++)
                    <td>
                        @php
                            $cellMeetings = $grid[$period][$day] ?? [];
                        @endphp

                        @if(count($cellMeetings) > 0)
                            @foreach($cellMeetings as $meeting)
                                @php
                                    $isStart = $meeting->start_period == $period;
                                    $displayEndPeriod = min($meeting->end_period, $maxPeriod);

                                    /*
                                     * Ở bản demo hiện tại:
                                     * Nếu một ô có nhiều lịch cùng thời điểm thì đánh dấu conflict.
                                     * Đây là dấu hiệu cảnh báo trực quan, chưa thay thế kiểm tra xung đột chính thức.
                                     */
                                    $hasConflict = count($cellMeetings) > 1;
                                @endphp

                                @if($isStart)
                                    <div class="meeting-card {{ $hasConflict ? 'conflict' : '' }}">
                                        <div class="section-code">
                                            {{ $meeting->section?->section_code }}
                                        </div>

                                        <div class="subject-name">
                                            {{ $meeting->section?->subject?->name ?? 'Không rõ học phần' }}
                                        </div>

                                        <div>
                                            Tiết {{ $meeting->start_period }}-{{ $displayEndPeriod }}
                                        </div>

                                        @if($meeting->section && $meeting->section->lecturers->count() > 0)
                                            <div class="lecturer">
                                                GV:
                                                {{ $meeting->section->lecturers->pluck('name')->join(', ') }}
                                            </div>
                                        @endif

                                        @if($meeting->room)
                                            <div class="room">
                                                Phòng: {{ $meeting->room->name }}
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <div class="meeting-card continuing {{ $hasConflict ? 'conflict' : '' }}">
                                        {{ $meeting->section?->section_code }} tiếp tục
                                    </div>
                                @endif
                            @endforeach
                        @else
                            <div class="timetable-empty">—</div>
                        @endif
                    </td>
                @endfor
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="note" style="margin-top: 16px;">
        <strong>Ghi chú:</strong>
        Các ô màu xanh là lịch học bình thường. Các ô màu đỏ là ô có nhiều lịch cùng thời điểm khi xem toàn bộ dữ liệu.
        Đây chỉ là dấu hiệu cảnh báo trực quan, chưa thay thế kiểm tra xung đột chính thức.
    </div>
@endsection
