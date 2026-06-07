@extends('layouts.app')

@section('title', 'Nhập / xuất thời khóa biểu')
@section('page_title', 'Nhập / xuất thời khóa biểu')

@php
    $dayLabel = fn ($day) => match ((int) $day) {
        2, 3, 4, 5, 6 => 'Thứ ' . $day,
        7 => 'Thứ 7',
        default => 'Ngoài khung xếp lịch',
    };

    $roomLabel = fn ($room) => match ($room?->type) {
        'online' => 'Online',
        'room', 'lab' => 'Phòng',
        default => 'Địa điểm',
    };
@endphp

@section('content')
    <section class="schedule-workspace">
        <div class="import-panel">
            <div>
                <div class="panel-kicker">Dữ liệu đầu vào</div>
                <h2>Import file Excel</h2>
                <p>Chọn file thời khóa biểu định dạng .xlsx hoặc .xls. Khi import file mới, hệ thống sẽ làm mới dữ liệu cũ để tránh trùng lặp.</p>
            </div>

            <form class="inline-import" action="{{ route('imports.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <label>
                    <span>File Excel thời khóa biểu</span>
                    <input type="file" name="excel_file" accept=".xlsx,.xls" required>
                </label>
                <button class="btn" type="submit">Import dữ liệu</button>
            </form>
        </div>

        <div class="schedule-tools">
            <div class="tool-card">
                <span>Tổng lịch học</span>
                <strong>{{ $meetings->count() }}</strong>
            </div>

            <div class="tool-card">
                <span>Đủ điều kiện xếp</span>
                <strong>{{ $dataQuality['sections_ready_to_schedule'] }}</strong>
                <small>Có lịch học và giảng viên hợp lệ</small>
            </div>

            <div class="tool-card {{ $totalConflicts > 0 ? 'danger-tool' : '' }}">
                <span>Kết quả kiểm định</span>
                <strong>{{ $totalConflicts }}</strong>
                <small>{{ $totalConflicts > 0 ? 'Còn xung đột cần tối ưu hoặc rà soát' : 'Lịch hợp lệ theo ràng buộc hiện tại' }}</small>
            </div>

            <div class="tool-card {{ ($dataQuality['sections_missing_meetings'] + $dataQuality['sections_missing_valid_lecturers']) > 0 ? 'warning-tool' : '' }}">
                <span>Cần rà soát</span>
                <strong>{{ $dataQuality['sections_missing_meetings'] + $dataQuality['sections_missing_valid_lecturers'] }}</strong>
                <small>{{ $dataQuality['sections_missing_meetings'] }} thiếu lịch, {{ $dataQuality['sections_missing_valid_lecturers'] }} thiếu giảng viên hợp lệ</small>
            </div>

            <a class="tool-card export-card" href="{{ route('exports.timetable') }}">
                <span>Đầu ra</span>
                <strong>Xuất Excel</strong>
                <small>Tải thời khóa biểu hiện tại</small>
            </a>
        </div>
    </section>

    <section class="schedule-panel">
        <div class="section-head">
            <div>
                <h2>Bảng thời khóa biểu</h2>
                <p>Hệ thống tự xếp vào Thứ 2-6, chỉ dùng Thứ 7 khi cần. Ô màu đỏ là lịch còn xung đột sau bước kiểm định/tối ưu.</p>
            </div>

            <div class="schedule-actions">
                <form class="mode-filter" method="GET" action="{{ route('imports.index') }}">
                    <label>
                        <span>Hình thức học</span>
                        <select name="study_mode" onchange="this.form.submit()">
                            <option value="all" @selected($studyMode === 'all')>Tất cả</option>
                            <option value="direct" @selected($studyMode === 'direct')>Trực tiếp</option>
                            <option value="online" @selected($studyMode === 'online')>Online</option>
                        </select>
                    </label>
                </form>
            </div>
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

                            @if(count($cellMeetings) > 0)
                                @foreach($cellMeetings as $meeting)
                                    @php
                                        $isStart = $meeting->start_period == $period;
                                        $displayEndPeriod = min($meeting->end_period, $maxPeriod);
                                        $hasConflict = in_array($meeting->id, $conflictMeetingIds, true);
                                    @endphp

                                    @if($isStart)
                                        <div class="meeting-card {{ $hasConflict ? 'conflict' : '' }}">
                                            <div class="section-code">
                                                {{ $meeting->section?->section_code }}
                                            </div>

                                            <div class="subject-name">
                                                {{ $meeting->section?->subject?->name ?? 'Không rõ môn học' }}
                                            </div>

                                            <div>
                                                Tiết {{ $meeting->start_period }}-{{ $displayEndPeriod }}
                                            </div>

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
                                @endforeach
                            @else
                                <div class="timetable-empty">—</div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    {{-- <section class="conflict-panel">
        <div class="section-head">
            <div>
                <h2>Xung đột cần rà soát</h2>
                <p>Hệ thống đã tự kiểm định dữ liệu và tự xếp các ca có thể xử lý. Danh sách dưới đây là những xung đột còn lại sau tối ưu, cần rà soát dữ liệu gốc hoặc điều chỉnh ràng buộc. Hệ thống luôn kiểm tra trùng giảng viên/lớp; riêng phòng học chỉ kiểm tra khi dữ liệu có mã phòng thật, còn Online/LMS/Zoom và địa điểm chung chỉ dùng để hiển thị.</p>
            </div>
        </div>

        @forelse($conflicts as $conflict)
            @php
                $meeting = $conflict->meeting;
                $conflictMeeting = $conflict->conflictMeeting;
                $typeLabel = match($conflict->type) {
                    'room_conflict' => 'Trùng phòng',
                    'lecturer_conflict' => 'Trùng giảng viên',
                    'section_conflict' => 'Trùng lớp học phần',
                    'mixed_conflict' => 'Nhiều xung đột',
                    'invalid_time_conflict' => 'Ngoài khung xếp lịch',
                    default => 'Xung đột lịch',
                };
            @endphp

            <article class="conflict-card">
                <div class="conflict-main">
                    <div class="badge badge-room">{{ $typeLabel }}</div>
                    <h3>{{ $meeting?->section?->section_code ?? '—' }} và {{ $conflictMeeting?->section?->section_code ?? '—' }}</h3>
                    <p>{{ $conflict->message }}</p>

                    <div class="conflict-lines">
                        <span>
                            <strong>Lịch cần sửa:</strong>
                            {{ $meeting?->section?->section_code ?? '—' }},
                            {{ $meeting?->day_of_week ? $dayLabel($meeting->day_of_week) : '—' }},
                            tiết {{ $meeting?->start_period ?? '—' }}-{{ $meeting?->end_period ?? '—' }},
                            {{ $roomLabel($meeting?->room) }} {{ $meeting?->room?->name ?? '—' }}
                        </span>
                        <span>
                            <strong>Đang trùng với:</strong>
                            {{ $conflictMeeting?->section?->section_code ?? '—' }},
                            {{ $conflictMeeting?->day_of_week ? $dayLabel($conflictMeeting->day_of_week) : '—' }},
                            tiết {{ $conflictMeeting?->start_period ?? '—' }}-{{ $conflictMeeting?->end_period ?? '—' }},
                            {{ $roomLabel($conflictMeeting?->room) }} {{ $conflictMeeting?->room?->name ?? '—' }}
                        </span>
                    </div>
                </div>
            </article>
        @empty
            <div class="empty-state">Không còn xung đột theo các ràng buộc hiện tại.</div>
        @endforelse
    </section> --}}

    {{-- Manual conflict actions were removed because scheduling now runs automatically after import. --}}
    {{-- <script>
        (() => {
            const container = document.querySelector('.container');

            const showNotice = (message, type = 'success') => {
                document.querySelectorAll('.ajax-alert').forEach((item) => item.remove());

                const notice = document.createElement('div');
                notice.className = `ajax-alert ${type === 'success' ? 'alert-success' : 'alert-error'}`;
                notice.textContent = message;
                container.prepend(notice);
            };

            const replaceSection = (doc, selector) => {
                const current = document.querySelector(selector);
                const next = doc.querySelector(selector);
                if (current && next) {
                    current.replaceWith(next);
                }
            };

            const refreshWorkspace = async (message, type = 'success') => {
                const response = await fetch(window.location.href, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const html = await response.text();
                const doc = new DOMParser().parseFromString(html, 'text/html');

                replaceSection(doc, '.schedule-tools');
                replaceSection(doc, '.schedule-panel');
                replaceSection(doc, '.conflict-panel');
                bindAjaxForms();
                showNotice(message, type);
            };

            const submitAjaxForm = async (form) => {
                const button = form.querySelector('button[type="submit"]');
                const originalText = button?.textContent;

                if (button) {
                    button.disabled = true;
                    button.textContent = 'Đang xử lý...';
                }

                try {
                    const response = await fetch(form.action, {
                        method: form.method || 'POST',
                        body: new FormData(form),
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    const data = await response.json();
                    await refreshWorkspace(data.message || 'Đã cập nhật dữ liệu.', response.ok && data.ok !== false ? 'success' : 'error');
                } catch (error) {
                    showNotice('Không thể xử lý thao tác này. Vui lòng thử lại.', 'error');
                } finally {
                    if (button) {
                        button.disabled = false;
                        button.textContent = originalText;
                    }
                }
            };

            const bindAjaxForms = () => {
                document.querySelectorAll('.removed-action-form').forEach((form) => {
                    form.addEventListener('submit', (event) => {
                        event.preventDefault();
                        submitAjaxForm(form);
                    });
                });
            };

            bindAjaxForms();
        })();
    </script> --}}
@endsection
