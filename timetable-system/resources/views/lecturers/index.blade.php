@extends('layouts.app')

@section('title', 'Danh sách giảng viên')
@section('page_title', 'Giảng viên')

@section('content')
    <section class="room-hero lecturer-hero">
        <div>
            <div class="panel-kicker">Nguồn lực giảng dạy</div>
            <h2>Danh sách giảng viên</h2>
            <p>
                Quản lý hồ sơ giảng viên, các môn đang đăng ký dạy và khung ngày/buổi có thể tham gia giảng dạy.
                Khi tạo thời khóa biểu, hệ thống dùng danh sách này để chọn lịch phù hợp.
            </p>
        </div>

        <form class="room-create-form lecturer-create-form" action="{{ route('lecturers.store') }}" method="POST" data-ajax-submit>
            @csrf
            <input type="text" name="name" placeholder="Tên giảng viên" required>
            <input type="email" name="email" placeholder="Email">
            <input type="text" name="phone" placeholder="Số điện thoại">
            <select name="availability_mode">
                <option value="unrestricted">Linh hoạt</option>
                <option value="limited">Giới hạn</option>
            </select>
            <button class="btn" type="submit">Thêm giảng viên</button>
        </form>
    </section>

    <form class="room-filter lecturer-filter" method="GET" action="{{ route('lecturers.index') }}" data-ajax-form data-live-search>
        <input
            type="text"
            name="keyword"
            value="{{ $keyword }}"
            placeholder="Tìm theo tên giảng viên, email, mã môn hoặc tên môn học..."
        >

        <button class="btn" type="submit">Tìm kiếm</button>
        <a class="btn btn-gray" href="{{ route('lecturers.index') }}" data-ajax-link>Làm mới</a>
    </form>

    <section class="lecturer-list">
        @forelse($lecturers as $lecturer)
            @php
                $availabilityMode = $lecturer->availability_mode ?? 'unrestricted';
                $selectedSlots = $lecturer->available_slots ?? [];
                $subjectGroups = $lecturer->sections
                    ->filter(fn ($section) => $section->subject)
                    ->groupBy('subject_id')
                    ->values();
            @endphp

            <article class="lecturer-card">
                <form class="lecturer-profile-form" action="{{ route('lecturers.update', $lecturer) }}" method="POST" data-availability-form data-ajax-submit>
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="keyword" value="{{ $keyword }}">
                    <input type="hidden" name="page" value="{{ $lecturers->currentPage() }}">

                    <div class="lecturer-card-head">
                        <div>
                            <strong>{{ $lecturer->name }}</strong>
                            <span>{{ $subjectGroups->count() }} môn học · {{ $lecturer->meetings_count }} lịch dạy</span>
                        </div>
                        <button class="btn btn-green" type="submit">Lưu thông tin</button>
                    </div>

                    <div class="lecturer-fields">
                        <input type="text" name="name" value="{{ $lecturer->name }}" placeholder="Tên giảng viên" required>
                        <input type="email" name="email" value="{{ $lecturer->email }}" placeholder="Email">
                        <input type="text" name="phone" value="{{ $lecturer->phone }}" placeholder="Số điện thoại">
                    </div>

                    <div class="lecturer-availability">
                        <label class="availability-mode-field">
                            <span>Khung dạy</span>
                            <select class="availability-mode-select" name="availability_mode">
                                <option value="unrestricted" @selected($availabilityMode !== 'limited')>Linh hoạt</option>
                                <option value="limited" @selected($availabilityMode === 'limited')>Giới hạn</option>
                            </select>
                        </label>

                        <div class="compact-availability availability-limited-panel {{ $availabilityMode === 'limited' ? '' : 'is-hidden' }}">
                            @foreach($days as $day => $dayLabel)
                                <div class="compact-day">
                                    <strong>{{ $dayLabel }}</strong>
                                    @foreach($sessions as $session => $sessionLabel)
                                        @php
                                            $slot = $day . '_' . $session;
                                        @endphp
                                        <label>
                                            <input type="checkbox" name="available_slots[]" value="{{ $slot }}" @checked(in_array($slot, $selectedSlots, true))>
                                            <span>{{ $sessionLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </form>

                <div class="lecturer-subjects">
                    <div class="lecturer-section-title">Môn đang dạy</div>

                    @forelse($subjectGroups as $sections)
                        @php
                            $subject = $sections->first()?->subject;
                            $sectionIds = $sections->pluck('id')->all();
                            $assignedMeetings = $lecturer->meetings
                                ->filter(fn ($meeting) => in_array($meeting->section_id, $sectionIds, true))
                                ->values();

                            if ($assignedMeetings->isEmpty()) {
                                $assignedMeetings = $sections
                                    ->flatMap(fn ($section) => $section->meetings)
                                    ->filter(fn ($meeting) => blank($meeting->lecturer_id) || (int) $meeting->lecturer_id === (int) $lecturer->id)
                                    ->sortBy([['day_of_week', 'asc'], ['start_period', 'asc']])
                                    ->values();
                            }
                        @endphp

                        <div class="lecturer-subject-row">
                            <div>
                                <strong>{{ $subject?->subject_code ?? 'Chưa rõ mã môn' }} - {{ $subject?->name ?? 'Chưa rõ môn học' }}</strong>
                                <small>{{ $sections->count() }} lớp học phần</small>

                                <div class="lecturer-schedule-chips">
                                    @forelse($assignedMeetings as $meeting)
                                        <span>
                                            {{ $meeting->displaySectionCode() }} ·
                                            Thứ {{ $meeting->day_of_week }},
                                            tiết {{ $meeting->start_period }}-{{ min($meeting->end_period, 12) }}
                                            · {{ $meeting->room?->name ?? 'chưa có phòng' }}
                                        </span>
                                    @empty
                                        <span>Chưa có lịch dạy</span>
                                    @endforelse
                                </div>
                            </div>

                            @if($subject)
                                <form
                                    action="{{ route('lecturers.subjects.detach', [$lecturer, $subject]) }}"
                                    method="POST"
                                    data-ajax-submit
                                    data-confirm-message="Hủy đăng ký môn này?"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="keyword" value="{{ $keyword }}">
                                    <input type="hidden" name="page" value="{{ $lecturers->currentPage() }}">
                                    <button type="submit" class="soft-danger-button">Hủy đăng ký</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <div class="empty-state compact-empty">Giảng viên chưa được gắn với môn học nào.</div>
                    @endforelse
                </div>

                <form
                    class="inline-delete"
                    action="{{ route('lecturers.destroy', $lecturer) }}"
                    method="POST"
                    data-ajax-submit
                    data-confirm-message="Xóa giảng viên này? Các môn và lịch đang gắn với giảng viên sẽ được bỏ liên kết."
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit">Xóa giảng viên</button>
                </form>
            </article>
        @empty
            <div class="empty-state">Chưa có giảng viên phù hợp.</div>
        @endforelse
    </section>

    @if($lecturers->hasPages())
        <div class="pagination-wrapper">
            <div class="pagination-info">
                Hiển thị {{ $lecturers->firstItem() }} - {{ $lecturers->lastItem() }}
                trong tổng số {{ $lecturers->total() }} giảng viên
            </div>

            <div class="pagination-list">
                @if($lecturers->onFirstPage())
                    <span class="disabled">« Trước</span>
                @else
                    <a href="{{ $lecturers->previousPageUrl() }}">« Trước</a>
                @endif

                @for($page = 1; $page <= $lecturers->lastPage(); $page++)
                    @if($page == $lecturers->currentPage())
                        <span class="active">{{ $page }}</span>
                    @else
                        <a href="{{ $lecturers->url($page) }}">{{ $page }}</a>
                    @endif
                @endfor

                @if($lecturers->hasMorePages())
                    <a href="{{ $lecturers->nextPageUrl() }}">Sau »</a>
                @else
                    <span class="disabled">Sau »</span>
                @endif
            </div>
        </div>
    @endif
@endsection
