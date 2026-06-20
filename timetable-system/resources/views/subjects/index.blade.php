@extends('layouts.app')

@section('title', 'Danh sách môn học')
@section('page_title', 'Danh sách môn học')

@section('content')
    <form class="filter subject-filter" method="GET" action="{{ route('subjects.index') }}">
        <input
            type="text"
            name="keyword"
            value="{{ $keyword }}"
            placeholder="Tìm theo mã môn học hoặc tên môn học..."
        >

        <button class="btn" type="submit">Tìm kiếm</button>
        <a class="btn btn-gray" href="{{ route('subjects.index') }}">Làm mới</a>
    </form>

    <div class="note">
        Mặc định giảng viên ở chế độ linh hoạt, nghĩa là hệ thống có thể xếp vào bất kỳ ngày/buổi nào còn trống.
        Chỉ chuyển sang chế độ giới hạn khi giảng viên chỉ dạy được một số ngày hoặc buổi cụ thể.
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
                            Thứ {{ $meeting->day_of_week }},
                            tiết {{ $meeting->start_period }}-{{ min($meeting->end_period, 12) }},
                            {{ $meeting->room?->name ?? 'chưa có phòng' }}
                        </span>
                    @empty
                        <span>Chưa có lịch học. Hãy kiểm tra ràng buộc giảng viên rồi bấm tạo thời khóa biểu.</span>
                    @endforelse
                </div>

                <details class="subject-add-lecturer" {{ $subjectLecturers->isEmpty() ? 'open' : '' }}>
                    <summary>Thêm giảng viên cho môn học</summary>

                    <form action="{{ route('subjects.lecturers.attach', $subject) }}" method="POST">
                        @csrf
                        <input type="hidden" name="keyword" value="{{ $keyword }}">
                        <input type="hidden" name="page" value="{{ $subjects->currentPage() }}">

                        <input type="text" name="name" placeholder="Tên giảng viên" required>
                        <input type="email" name="email" placeholder="Email nếu có">
                        <button class="btn" type="submit">Thêm giảng viên</button>
                    </form>

                    <small>
                        Giảng viên mới sẽ được gắn vào các lớp học phần của môn này và mặc định ở chế độ linh hoạt.
                    </small>
                </details>

                <div class="subject-lecturer-list">
                    @forelse($subjectLecturers as $lecturer)
                        @php
                            $availabilityMode = $lecturer->availability_mode ?? 'unrestricted';
                            $selectedSlots = $lecturer->available_slots ?? [];
                        @endphp

                        <form class="subject-lecturer-card" action="{{ route('subjects.lecturers.availability', $lecturer) }}" method="POST">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="keyword" value="{{ $keyword }}">
                            <input type="hidden" name="page" value="{{ $subjects->currentPage() }}">

                            <div class="subject-lecturer-head">
                                <div>
                                    <strong>{{ $lecturer->name }}</strong>
                                    <small class="availability-summary">{{ $availabilityMode === 'limited' ? 'Giới hạn' : 'Linh hoạt' }}</small>
                                </div>
                                <span class="availability-save-status" aria-live="polite"></span>
                            </div>

                            <label class="availability-mode-field">
                                <span>Kiểu lịch</span>
                                <select class="availability-mode-select" name="availability_mode">
                                    <option value="unrestricted" @selected($availabilityMode !== 'limited')>
                                        Linh hoạt
                                    </option>
                                    <option value="limited" @selected($availabilityMode === 'limited')>
                                        Giới hạn
                                    </option>
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
                        </form>
                    @empty
                        <div class="empty-state">Môn học này chưa có giảng viên hợp lệ.</div>
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

    <script>
        document.querySelectorAll('.subject-lecturer-card').forEach((card) => {
            const select = card.querySelector('.availability-mode-select');
            const panel = card.querySelector('.availability-limited-panel');
            const status = card.querySelector('.availability-save-status');
            const summary = card.querySelector('.availability-summary');

            let saveTimer = null;
            let abortController = null;

            if (!select || !panel) {
                return;
            }

            const syncPanel = () => {
                panel.classList.toggle('is-hidden', select.value !== 'limited');

                if (summary) {
                    summary.textContent = select.value === 'limited' ? 'Giới hạn' : 'Linh hoạt';
                }
            };

            const setStatus = (message, state = '') => {
                if (!status) {
                    return;
                }

                status.textContent = message;
                status.dataset.state = state;
            };

            const submitAvailability = () => {
                window.clearTimeout(saveTimer);
                setStatus('Đang lưu...', 'saving');

                if (abortController) {
                    abortController.abort();
                }

                abortController = new AbortController();

                fetch(card.action, {
                    method: 'POST',
                    body: new FormData(card),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    signal: abortController.signal,
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('Không lưu được dữ liệu');
                        }

                        return response.json();
                    })
                    .then((data) => {
                        const savedSlots = Array.isArray(data.available_slots) ? data.available_slots : [];

                        card.querySelectorAll('input[name="available_slots[]"]').forEach((checkbox) => {
                            checkbox.checked = savedSlots.includes(checkbox.value);
                        });

                        setStatus('Đã lưu', 'saved');
                    })
                    .catch((error) => {
                        if (error.name === 'AbortError') {
                            return;
                        }

                        setStatus('Lỗi lưu', 'error');
                    });
            };

            const debounceSave = () => {
                window.clearTimeout(saveTimer);
                saveTimer = window.setTimeout(submitAvailability, 300);
            };

            select.addEventListener('change', () => {
                syncPanel();
                submitAvailability();
            });

            card.querySelectorAll('input[type="checkbox"]').forEach((checkbox) => {
                checkbox.addEventListener('change', () => {
                    if (select.value === 'limited') {
                        debounceSave();
                    }
                });
            });

            syncPanel();
        });
    </script>
@endsection
