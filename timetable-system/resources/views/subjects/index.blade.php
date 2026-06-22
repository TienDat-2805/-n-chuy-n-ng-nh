@extends('layouts.app')

@section('title', 'Danh sách môn học')
@section('page_title', 'Môn học')

@section('content')
    <form class="filter subject-filter" method="GET" action="{{ route('subjects.index') }}" data-ajax-form data-live-search>
        <input
            type="text"
            name="keyword"
            value="{{ $keyword }}"
            placeholder="Tìm theo mã môn, tên môn hoặc tên giảng viên..."
        >

        <button class="btn" type="submit">Tìm kiếm</button>
        <a class="btn btn-gray" href="{{ route('subjects.index') }}" data-ajax-link>Làm mới</a>
    </form>

    <div class="note">
        Trang này dùng để kiểm tra môn học và gắn giảng viên từ danh sách đã có. Lịch có thể dạy của giảng viên được chỉnh tại trang Giảng viên.
    </div>

    <div class="subject-list">
        @forelse($subjects as $subject)
            @php
                $subjectLecturers = $subject->sections
                    ->flatMap(fn ($section) => $section->lecturers)
                    ->unique('id')
                    ->sortBy('name')
                    ->values();

                $subjectMeetings = $subject->sections
                    ->flatMap(fn ($section) => $section->meetings)
                    ->sortBy([['day_of_week', 'asc'], ['start_period', 'asc']])
                    ->values();

                $attachedLecturerIds = $subjectLecturers->pluck('id')->all();
            @endphp

            <article class="subject-card">
                <div class="subject-card-head">
                    <div>
                        <div class="subject-code">{{ $subject->subject_code }}</div>
                        <h2>{{ $subject->name }}</h2>
                    </div>

                    <div class="subject-meta">
                        <span>{{ $subjectLecturers->count() }} giảng viên</span>
                        <span>{{ $subjectMeetings->count() > 0 ? 'Đã xếp lịch' : 'Chưa xếp lịch' }}</span>
                    </div>
                </div>

                <div class="subject-schedule-summary">
                    @forelse($subjectMeetings as $meeting)
                        <span>
                            {{ $meeting->displaySectionCode() }},
                            Thứ {{ $meeting->day_of_week }},
                            tiết {{ $meeting->start_period }}-{{ min($meeting->end_period, 12) }},
                            {{ $meeting->room?->name ?? 'chưa có phòng' }}
                            @if($meeting->displayLecturerName())
                                · GV: {{ $meeting->displayLecturerName() }}
                            @endif
                        </span>
                    @empty
                        <span>Chưa có lịch học. Hãy kiểm tra danh sách giảng viên và bấm tạo thời khóa biểu.</span>
                    @endforelse
                </div>

                <details class="subject-add-lecturer" {{ $subjectLecturers->isEmpty() ? 'open' : '' }}>
                    <summary>Gắn giảng viên cho môn học</summary>

                    <form action="{{ route('subjects.lecturers.attach', $subject) }}" method="POST" data-async-subject-lecturer-form>
                        @csrf
                        <input type="hidden" name="keyword" value="{{ $keyword }}">
                        <input type="hidden" name="page" value="{{ $subjects->currentPage() }}">

                        <select name="lecturer_id" required>
                            <option value="">Chọn giảng viên</option>
                            @foreach($lecturers as $lecturer)
                                <option value="{{ $lecturer->id }}" @disabled(in_array($lecturer->id, $attachedLecturerIds, true))>
                                    {{ $lecturer->name }}
                                </option>
                            @endforeach
                        </select>

                        <button class="btn" type="submit">Gắn giảng viên</button>
                        <span class="async-form-status" data-async-status aria-live="polite"></span>
                    </form>

                    <small>
                        Nếu chưa có giảng viên trong danh sách, hãy thêm tại trang Giảng viên trước rồi quay lại gắn vào môn.
                    </small>
                </details>

                <div class="subject-lecturer-list simple-subject-lecturers">
                    @forelse($subjectLecturers as $lecturer)
                        <div class="subject-lecturer-card readonly-lecturer-card">
                            <div class="subject-lecturer-head">
                                <div>
                                    <strong>{{ $lecturer->name }}</strong>
                                    <small>{{ ($lecturer->availability_mode ?? 'unrestricted') === 'limited' ? 'Giới hạn lịch dạy' : 'Linh hoạt' }}</small>
                                </div>

                                <form
                                    action="{{ route('subjects.lecturers.detach', [$subject, $lecturer]) }}"
                                    method="POST"
                                    data-async-subject-lecturer-form
                                    data-confirm-message="Bỏ giảng viên khỏi môn học này?"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="keyword" value="{{ $keyword }}">
                                    <input type="hidden" name="page" value="{{ $subjects->currentPage() }}">
                                    <button type="submit" class="soft-danger-button">Bỏ khỏi môn</button>
                                    <span class="async-form-status" data-async-status aria-live="polite"></span>
                                </form>
                            </div>
                        </div>
                    @empty
                        <div class="empty-state">Môn học này chưa có giảng viên hợp lệ.</div>
                    @endforelse
                </div>
            </article>
        @empty
            <div class="empty-state">Chưa có dữ liệu môn học phù hợp.</div>
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
