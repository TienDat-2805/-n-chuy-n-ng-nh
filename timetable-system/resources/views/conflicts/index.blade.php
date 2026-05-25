@extends('layouts.app')

@section('title', 'Kiểm tra xung đột lịch')
@section('page_title', 'Kiểm tra xung đột lịch')

@section('content')
    <div class="toolbar">
        <div>
            <strong>Tổng số xung đột:</strong> {{ $conflicts->total() }}

            <div class="muted">
                Hệ thống kiểm tra trùng giảng viên, trùng phòng học/địa điểm và trùng lịch của cùng một lớp học phần.
                Với dữ liệu hiện tại, cột phòng chủ yếu là địa điểm chung như Hòa Lạc hoặc Mỹ Đình nên lỗi trùng phòng cần được giải thích là cảnh báo tham khảo.
            </div>
        </div>

        <form method="POST" action="{{ route('conflicts.check') }}">
            @csrf

            <button class="btn btn-red" type="submit">
                Kiểm tra xung đột
            </button>
        </form>
    </div>

    <table>
        <thead>
        <tr>
            <th>Loại lỗi</th>
            <th>Lớp học phần A</th>
            <th>Lớp học phần B</th>
            <th>Thời gian</th>
            <th>Phòng/Địa điểm</th>
            <th>Mô tả</th>
        </tr>
        </thead>

        <tbody>
        @forelse($conflicts as $conflict)
            @php
                $a = $conflict->meeting;
                $b = $conflict->conflictMeeting;

                $badgeClass = match($conflict->type) {
                    'room_conflict' => 'badge-room',
                    'lecturer_conflict' => 'badge-lecturer',
                    'section_conflict' => 'badge-section',
                    default => 'badge-section',
                };

                $typeLabel = match($conflict->type) {
                    'room_conflict' => 'Trùng phòng/địa điểm',
                    'lecturer_conflict' => 'Trùng giảng viên',
                    'section_conflict' => 'Trùng lớp HP',
                    default => $conflict->type,
                };
            @endphp

            <tr>
                <td>
                    <span class="badge {{ $badgeClass }}">
                        {{ $typeLabel }}
                    </span>
                </td>

                <td>
                    <div class="section-code">
                        {{ $a?->section?->section_code ?? '—' }}
                    </div>

                    <div>
                        {{ $a?->section?->subject?->name ?? '—' }}
                    </div>

                    <div class="muted">
                        GV:
                        {{ $a?->section?->lecturers?->pluck('name')->join(', ') ?: '—' }}
                    </div>
                </td>

                <td>
                    <div class="section-code">
                        {{ $b?->section?->section_code ?? '—' }}
                    </div>

                    <div>
                        {{ $b?->section?->subject?->name ?? '—' }}
                    </div>

                    <div class="muted">
                        GV:
                        {{ $b?->section?->lecturers?->pluck('name')->join(', ') ?: '—' }}
                    </div>
                </td>

                <td>
                    Thứ {{ $a?->day_of_week ?? '—' }},
                    tiết {{ $a?->start_period ?? '—' }}-{{ $a?->end_period ? min($a->end_period, 12) : '—' }}

                    <br>

                    <span class="muted">
                        So với tiết {{ $b?->start_period ?? '—' }}-{{ $b?->end_period ? min($b->end_period, 12) : '—' }}
                    </span>
                </td>

                <td>
                    <div>
                        A: {{ $a?->room?->name ?? '—' }}
                    </div>

                    <div>
                        B: {{ $b?->room?->name ?? '—' }}
                    </div>
                </td>

                <td>
                    {{ $conflict->message }}
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" class="empty" style="text-align: center; padding: 24px;">
                    Chưa có dữ liệu xung đột. Hãy bấm nút "Kiểm tra xung đột".
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

    @if($conflicts->hasPages())
        <div class="pagination-wrapper">
            <div class="pagination-info">
                Hiển thị {{ $conflicts->firstItem() }} - {{ $conflicts->lastItem() }}
                trong tổng số {{ $conflicts->total() }} xung đột
            </div>

            <div class="pagination-list">
                @if($conflicts->onFirstPage())
                    <span class="disabled">« Trước</span>
                @else
                    <a href="{{ $conflicts->previousPageUrl() }}">« Trước</a>
                @endif

                @for($page = 1; $page <= $conflicts->lastPage(); $page++)
                    @if($page == $conflicts->currentPage())
                        <span class="active">{{ $page }}</span>
                    @else
                        <a href="{{ $conflicts->url($page) }}">{{ $page }}</a>
                    @endif
                @endfor

                @if($conflicts->hasMorePages())
                    <a href="{{ $conflicts->nextPageUrl() }}">Sau »</a>
                @else
                    <span class="disabled">Sau »</span>
                @endif
            </div>
        </div>
    @endif
@endsection
