@extends('layouts.app')

@section('title', 'Danh sách môn học')
@section('page_title', 'Danh sách môn học')

@section('content')
    <form class="filter subject-filter" method="GET" action="{{ route('subjects.index') }}">
        <input
            type="text"
            name="keyword"
            value="{{ $keyword }}"
            placeholder="Tìm theo mã môn học, tên môn học hoặc mã lớp học phần..."
        >

        <button class="btn" type="submit">Tìm kiếm</button>
        <a class="btn btn-gray" href="{{ route('subjects.index') }}">Làm mới</a>
    </form>

    <div class="subject-list">
        @forelse($subjects as $subject)
            <article class="subject-card">
                <div class="subject-card-head">
                    <div>
                        <div class="subject-code">{{ $subject->subject_code }}</div>
                        <h2>{{ $subject->name }}</h2>
                    </div>

                    <div class="subject-meta">
                        <span>{{ $subject->credits ?? 0 }} tín chỉ</span>
                        <span>{{ $subject->sections_count }} lớp học phần</span>
                    </div>
                </div>

                <div class="subject-detail-grid">
                    <div>
                        <span>Lý thuyết</span>
                        <strong>{{ $subject->theory_credits ?? '—' }}</strong>
                    </div>
                    <div>
                        <span>Thực hành</span>
                        <strong>{{ $subject->practice_credits ?? '—' }}</strong>
                    </div>
                    <div>
                        <span>Tự học</span>
                        <strong>{{ $subject->self_study_credits ?? '—' }}</strong>
                    </div>
                </div>

                <div class="section-mini-list">
                    @forelse($subject->sections->take(4) as $section)
                        @php
                            $hasConflict = in_array($section->id, $conflictSectionIds, true);
                            $sectionConflictMessages = $conflictMessagesBySection[$section->id] ?? [];
                        @endphp

                        <div class="section-mini-item {{ $hasConflict ? 'section-conflict' : '' }}">
                            <div>
                                <div class="section-title-line">
                                    <strong>{{ $section->section_code }}</strong>
                                    @if($hasConflict)
                                        <span class="conflict-chip">Cần xếp lại</span>
                                    @endif
                                </div>
                                <span>{{ $section->lecturers->pluck('name')->join(', ') ?: 'Chưa có giảng viên' }}</span>
                            </div>

                            <div>
                                @if($hasConflict)
                                    @foreach($sectionConflictMessages as $message)
                                        <small class="conflict-note">{{ $message }}</small>
                                    @endforeach
                                    <small class="conflict-note">Lớp này chưa được đưa vào bảng thời khóa biểu chính để nhà trường xem xét xếp lại.</small>
                                @else
                                @forelse($section->meetings as $meeting)
                                    <small>
                                        Thứ {{ $meeting->day_of_week }}, tiết {{ $meeting->start_period }}-{{ min($meeting->end_period, 12) }}
                                    </small>
                                @empty
                                    <small>Chưa có lịch học</small>
                                @endforelse
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="empty-state">Môn học này chưa có lớp học phần.</div>
                    @endforelse
                </div>
            </article>
        @empty
            <div class="empty-state">Chưa có dữ liệu môn học.</div>
        @endforelse
    </div>

    @if($subjects->hasPages())
        <div class="pagination-wrapper">
            <div class="pagination-info">
                Hiển thị {{ $subjects->firstItem() }} - {{ $subjects->lastItem() }}
                trong tổng số {{ $subjects->total() }} môn học
            </div>

            <div class="pagination-list">
                @if($subjects->onFirstPage())
                    <span class="disabled">« Trước</span>
                @else
                    <a href="{{ $subjects->previousPageUrl() }}">« Trước</a>
                @endif

                @for($page = 1; $page <= $subjects->lastPage(); $page++)
                    @if($page == $subjects->currentPage())
                        <span class="active">{{ $page }}</span>
                    @else
                        <a href="{{ $subjects->url($page) }}">{{ $page }}</a>
                    @endif
                @endfor

                @if($subjects->hasMorePages())
                    <a href="{{ $subjects->nextPageUrl() }}">Sau »</a>
                @else
                    <span class="disabled">Sau »</span>
                @endif
            </div>
        </div>
    @endif
@endsection
