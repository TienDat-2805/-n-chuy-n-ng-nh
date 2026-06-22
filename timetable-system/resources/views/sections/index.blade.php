@extends('layouts.app')

@section('title', 'Danh sách lớp học phần')
@section('page_title', 'Danh sách lớp học phần')

@section('content')
    <form class="filter" method="GET" action="{{ route('sections.index') }}" data-ajax-form data-live-search>
        <input
            type="text"
            name="keyword"
            value="{{ $keyword }}"
            placeholder="Tìm theo mã lớp, mã học phần, tên học phần hoặc giảng viên..."
        >

        <button class="btn btn-green" type="submit">Tìm kiếm</button>
        <a class="btn btn-gray" href="{{ route('sections.index') }}" data-ajax-link>Làm mới</a>
    </form>

    <table>
        <thead>
        <tr>
            <th>Mã lớp HP</th>
            <th>Học phần</th>
            <th>CTĐT</th>
            <th>Khoa</th>
            <th>Số SV</th>
            <th>Giảng viên</th>
            <th>Lịch học</th>
            <th>Hình thức</th>
            <th>Chi tiết</th>
        </tr>
        </thead>
        <tbody>
        @forelse($sections as $section)
            <tr>
                <td>
                    <strong>{{ $section->section_code }}</strong>
                </td>

                <td>
                    @if($section->subject)
                        <div><strong>{{ $section->subject->subject_code }}</strong></div>
                        <div>{{ $section->subject->name }}</div>
                    @else
                        <span class="empty">Chưa có học phần</span>
                    @endif
                </td>

                <td>
                    {{ $section->program?->code ?? '—' }}
                </td>

                <td>
                    {{ $section->cohort?->name ?? '—' }}
                </td>

                <td>
                    {{ $section->max_students ?? '—' }}
                </td>

                <td>
                    @forelse($section->lecturers as $lecturer)
                        <div class="badge">{{ $lecturer->name }}</div>
                    @empty
                        <span class="empty">Chưa có GV</span>
                    @endforelse
                </td>

                <td>
                    @forelse($section->meetings as $meeting)
                        <div class="schedule-line">
                            Thứ {{ $meeting->day_of_week }},
                            tiết {{ $meeting->start_period }}-{{ min($meeting->end_period, 12) }}

                            @if($meeting->room)
                                <br>
                                <span class="muted">Phòng: {{ $meeting->room->name }}</span>
                            @endif
                        </div>
                    @empty
                        <span class="empty">Chưa có lịch</span>
                    @endforelse
                </td>

                <td>
                    {{ $section->teaching_mode ?? '—' }}
                </td>

                <td>
                    <a class="detail-link" href="{{ route('sections.show', $section) }}">
                        Xem
                    </a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="9">Không có dữ liệu lớp học phần.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    @if($sections->hasPages())
        <div class="pagination-wrapper">
            <div class="pagination-info">
                Hiển thị {{ $sections->firstItem() }} - {{ $sections->lastItem() }}
                trong tổng số {{ $sections->total() }} lớp học phần
            </div>

            <div class="pagination-list">
                @if($sections->onFirstPage())
                    <span class="disabled">« Trước</span>
                @else
                    <a href="{{ $sections->previousPageUrl() }}">« Trước</a>
                @endif

                @for($page = 1; $page <= $sections->lastPage(); $page++)
                    @if($page == $sections->currentPage())
                        <span class="active">{{ $page }}</span>
                    @else
                        <a href="{{ $sections->url($page) }}">{{ $page }}</a>
                    @endif
                @endfor

                @if($sections->hasMorePages())
                    <a href="{{ $sections->nextPageUrl() }}">Sau »</a>
                @else
                    <span class="disabled">Sau »</span>
                @endif
            </div>
        </div>
    @endif
@endsection
