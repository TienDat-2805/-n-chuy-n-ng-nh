@extends('layouts.app')

@section('title', 'Kiểm tra lịch')
@section('page_title', 'Kiểm tra lịch')

@section('content')
    @php
        $badgeClasses = [
            'room_conflict' => 'badge-room',
            'lecturer_conflict' => 'badge-lecturer',
            'section_conflict' => 'badge-section',
            'mixed_conflict' => 'badge-section',
            'invalid_time_conflict' => 'badge-section',
            'lecturer_availability_conflict' => 'badge-lecturer',
            'room_assignment_conflict' => 'badge-room',
            'lecturer_campus_conflict' => 'badge-lecturer',
        ];

        $formatTime = function ($meeting) {
            if (! $meeting) {
                return '-';
            }

            return 'Thứ ' . $meeting->day_of_week . ', tiết ' . $meeting->start_period . '-' . min((int) $meeting->end_period, 12);
        };

        $meetingTitle = function ($meeting) {
            if (! $meeting) {
                return '-';
            }

            $code = $meeting->section?->section_code;
            $subject = $meeting->section?->subject?->name;

            return trim(($code ? "{$code} - " : '') . ($subject ?: '-'));
        };

        $relevantLecturers = function ($conflict) {
            $aLecturers = $conflict->meeting?->section?->lecturers ?? collect();
            $bLecturers = $conflict->conflictMeeting?->section?->lecturers ?? collect();
            $allLecturers = $aLecturers->merge($bLecturers)->unique('id')->values();

            $fromMessage = $allLecturers
                ->filter(fn ($lecturer) => $lecturer->name && str_contains($conflict->message ?? '', $lecturer->name))
                ->values();

            if ($fromMessage->isNotEmpty()) {
                return $fromMessage->pluck('name')->join(', ');
            }

            if ($bLecturers->isNotEmpty()) {
                $shared = $aLecturers
                    ->filter(fn ($lecturer) => $bLecturers->contains('id', $lecturer->id))
                    ->values();

                if ($shared->isNotEmpty()) {
                    return $shared->pluck('name')->join(', ');
                }
            }

            return null;
        };

        $focusInfo = function ($conflict) use ($relevantLecturers) {
            $a = $conflict->meeting;
            $b = $conflict->conflictMeeting;

            if (str_contains($conflict->type, 'lecturer')) {
                return [
                    'label' => 'Giảng viên',
                    'value' => $relevantLecturers($conflict) ?: 'Chưa xác định',
                ];
            }

            if (str_contains($conflict->type, 'room')) {
                return [
                    'label' => 'Phòng học',
                    'value' => $a?->room?->name ?: ($b?->room?->name ?: 'Chưa xác định'),
                ];
            }

            return [
                'label' => 'Lớp học phần',
                'value' => $a?->section?->section_code ?: 'Chưa xác định',
            ];
        };
    @endphp

    <section class="schedule-workspace conflict-workspace">
        <div class="import-panel">
            <div>
                <div class="panel-kicker">Rà soát thời khóa biểu</div>
                <h2>Kiểm tra và phân loại xung đột</h2>
                <p>
                    Trang này gom các vấn đề theo nhóm để dễ xử lý: lỗi cần sửa ngay như trùng phòng, trùng giảng viên,
                    trùng tiết; và cảnh báo như giảng viên bị xếp dạy nhiều cơ sở trong cùng một ngày.
                </p>
            </div>

            <form class="inline-import" method="POST" action="{{ route('conflicts.check') }}">
                @csrf
                <button class="btn btn-red" type="submit">Kiểm tra lại lịch</button>
            </form>
        </div>
    </section>

    <section class="conflict-filter-panel">
        <div class="conflict-group-tabs">
            @foreach($groupLabels as $group => $label)
                <a
                    class="{{ $selectedGroup === $group ? 'active' : '' }}"
                    href="{{ route('conflicts.index', array_filter(['group' => $group, 'keyword' => $keyword ?: null])) }}"
                >
                    <span>{{ $label }}</span>
                    <strong>{{ $groupCounts->get($group, 0) }}</strong>
                </a>
            @endforeach
        </div>

        <form class="conflict-filter-form" method="GET" action="{{ route('conflicts.index') }}">
            <input type="hidden" name="group" value="{{ $selectedGroup }}">

            <label>
                <span>Loại lỗi</span>
                <select name="type">
                    <option value="all" @selected($selectedType === 'all')>Tất cả loại</option>
                    @foreach($typeLabels as $type => $label)
                        <option value="{{ $type }}" @selected($selectedType === $type)>
                            {{ $label }} ({{ $typeCounts->get($type, 0) }})
                        </option>
                    @endforeach
                </select>
            </label>

            <label>
                <span>Tìm kiếm</span>
                <input name="keyword" value="{{ $keyword }}" placeholder="Mã lớp, môn học, giảng viên, phòng...">
            </label>

            <button class="btn" type="submit">Lọc</button>
            <a class="btn btn-gray" href="{{ route('conflicts.index') }}">Làm mới</a>
        </form>
    </section>

    <section class="schedule-panel">
        <div class="section-head">
            <div>
                <h2>Danh sách cảnh báo</h2>
                <p>
                    Đang hiển thị {{ $conflicts->total() }} cảnh báo theo bộ lọc hiện tại.
                    Mỗi lần bấm kiểm tra lại, danh sách này sẽ được làm mới theo thời khóa biểu mới nhất.
                </p>
            </div>
        </div>

        <div class="conflict-table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Phân loại</th>
                    <th>Môn/lớp</th>
                    <th>Đối tượng</th>
                    <th>Mô tả</th>
                    <th>Điều chỉnh</th>
                </tr>
                </thead>

                <tbody>
                @forelse($conflicts as $conflict)
                    @php
                        $a = $conflict->meeting;
                        $b = $conflict->conflictMeeting;
                        $typeLabel = $typeLabels[$conflict->type] ?? $conflict->type;
                        $badgeClass = $badgeClasses[$conflict->type] ?? 'badge-section';
                        $focus = $focusInfo($conflict);
                    @endphp

                    <tr data-conflict-row data-conflict-id="{{ $conflict->id }}" data-meeting-id="{{ $a?->id }}">
                        <td>
                            <span class="badge {{ $badgeClass }}">{{ $typeLabel }}</span>
                        </td>

                        <td>
                            <div class="conflict-pair-list">
                                <div>
                                    <span>A</span>
                                    <strong>{{ $meetingTitle($a) }}</strong>
                                </div>

                                @if($b)
                                    <div>
                                        <span>B</span>
                                        <strong>{{ $meetingTitle($b) }}</strong>
                                    </div>
                                @endif
                            </div>
                        </td>

                        <td>
                            <div class="conflict-focus-label">{{ $focus['label'] }}</div>
                            <div class="conflict-focus-value">{{ $focus['value'] }}</div>
                        </td>

                        <td>
                            <div class="conflict-time-line">
                                <strong>A:</strong> {{ $formatTime($a) }}
                                @if($a?->room)
                                    <span>{{ $a->room->name }}</span>
                                    @if($a->room->campus)
                                        <em>{{ $a->room->campus }}</em>
                                    @endif
                                @endif
                            </div>

                            @if($b)
                                <div class="conflict-time-line">
                                    <strong>B:</strong> {{ $formatTime($b) }}
                                    @if($b?->room)
                                        <span>{{ $b->room->name }}</span>
                                        @if($b->room->campus)
                                            <em>{{ $b->room->campus }}</em>
                                        @endif
                                    @endif
                                </div>
                            @endif
                        </td>

                        <td class="adjustment-cell" data-adjustment-cell>
                            <div class="adjustment-target-picker">
                                <small>Chọn lịch cần điều chỉnh</small>

                                <button
                                    class="adjustment-target-button"
                                    type="button"
                                    data-target-button
                                    data-target-meeting-id="{{ $a?->id }}"
                                    data-target-label="A"
                                >
                                    Chỉnh A
                                </button>

                                @if($b)
                                    <button
                                        class="adjustment-target-button"
                                        type="button"
                                        data-target-button
                                        data-target-meeting-id="{{ $b->id }}"
                                        data-target-label="B"
                                    >
                                        Chỉnh B
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="empty" style="text-align: center; padding: 24px;">
                            Không có cảnh báo phù hợp với bộ lọc hiện tại.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($conflicts->hasPages())
        <div class="pagination-wrapper">
            <div class="pagination-info">
                Hiển thị {{ $conflicts->firstItem() }} - {{ $conflicts->lastItem() }}
                trong tổng số {{ $conflicts->total() }} cảnh báo
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

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const rows = Array.from(document.querySelectorAll('[data-conflict-row]'));

            if (rows.length === 0) {
                return;
            }

            const csrfToken = @json(csrf_token());
            const suggestionsUrl = @json(route('conflicts.suggestions'));
            const applyUrl = @json(route('conflicts.apply'));

            rows.forEach((row) => {
                row.adjustmentTargets = Array.from(row.querySelectorAll('[data-target-button]')).map((button) => {
                    return {
                        meetingId: button.dataset.targetMeetingId,
                        label: button.dataset.targetLabel,
                    };
                });

                attachTargetButtons(row);
            });

            function attachTargetButtons(row) {
                row.querySelectorAll('[data-target-button]').forEach((button) => {
                    button.addEventListener('click', () => loadSuggestions(row, button));
                });
            }

            async function loadSuggestions(row, button) {
                const targetButtons = row.querySelectorAll('[data-target-button]');
                targetButtons.forEach((item) => {
                    item.disabled = true;
                    item.classList.toggle('active', item === button);
                });

                renderStatus(row, `Đang tìm phương án cho lịch ${button.dataset.targetLabel || ''}...`, 'loading');

                try {
                    const response = await fetch(suggestionsUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            conflict_id: Number(row.dataset.conflictId),
                            target_meeting_id: Number(button.dataset.targetMeetingId),
                        }),
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Không thể tính phương án điều chỉnh.');
                    }

                    renderSuggestions(row, data.suggestions || []);
                } catch (error) {
                    renderTargetPicker(row, error.message || 'Không thể tính phương án điều chỉnh.');
                }
            }

            function renderSuggestions(row, suggestions) {
                const cell = row.querySelector('[data-adjustment-cell]');

                if (!cell) {
                    return;
                }

                cell.replaceChildren();

                if (suggestions.length === 0) {
                    renderStatus(row, 'Chưa tìm được phương án phù hợp.', 'muted');
                    return;
                }

                const list = document.createElement('div');
                list.className = 'adjustment-list';

                suggestions.forEach((suggestion) => {
                    const option = document.createElement('div');
                    option.className = 'adjustment-option';

                    const content = document.createElement('div');

                    const title = document.createElement('strong');
                    const targetLabel = suggestion.target_label || 'A';
                    const targetTitle = suggestion.target_title || 'Lịch học phần';
                    title.textContent = `Chỉnh lịch ${targetLabel}: ${targetTitle}`;

                    const label = document.createElement('span');
                    label.textContent = [suggestion.title, suggestion.label].filter(Boolean).join(' · ');

                    const hint = document.createElement('small');
                    hint.textContent = suggestion.hint || '';

                    content.append(title, label, hint);

                    const button = document.createElement('button');
                    button.className = 'btn adjustment-save';
                    button.type = 'button';
                    button.textContent = 'Lưu';
                    button.addEventListener('click', () => applySuggestion(row, button, {
                        meeting_id: suggestion.meeting_id,
                        day_of_week: suggestion.day_of_week,
                        start_period: suggestion.start_period,
                        end_period: suggestion.end_period,
                        room_id: suggestion.room_id,
                    }));

                    option.append(content, button);
                    list.append(option);
                });

                const changeTarget = document.createElement('button');
                changeTarget.className = 'adjustment-change-target';
                changeTarget.type = 'button';
                changeTarget.textContent = 'Chọn lịch khác';
                changeTarget.addEventListener('click', () => renderTargetPicker(row));

                cell.append(list, changeTarget);
            }

            function renderTargetPicker(row, message = '') {
                const cell = row.querySelector('[data-adjustment-cell]');

                if (!cell) {
                    return;
                }

                const picker = document.createElement('div');
                picker.className = 'adjustment-target-picker';

                if (message) {
                    const notice = document.createElement('small');
                    notice.className = 'adjustment-target-message';
                    notice.textContent = message;
                    picker.append(notice);
                } else {
                    const label = document.createElement('small');
                    label.textContent = 'Chọn lịch cần điều chỉnh';
                    picker.append(label);
                }

                (row.adjustmentTargets || []).forEach((target) => {
                    const button = document.createElement('button');
                    button.className = 'adjustment-target-button';
                    button.type = 'button';
                    button.dataset.targetButton = '';
                    button.dataset.targetMeetingId = target.meetingId;
                    button.dataset.targetLabel = target.label;
                    button.textContent = `Chỉnh ${target.label}`;
                    button.addEventListener('click', () => loadSuggestions(row, button));
                    picker.append(button);
                });

                cell.replaceChildren(picker);
            }

            function renderStatus(row, message, type = 'muted') {
                const cell = row.querySelector('[data-adjustment-cell]');

                if (!cell) {
                    return;
                }

                const status = document.createElement('span');
                status.className = type === 'loading'
                    ? 'adjustment-loading'
                    : (type === 'error' ? 'adjustment-status is-error' : 'adjustment-status');
                status.textContent = message;

                cell.replaceChildren(status);
            }

            async function applySuggestion(row, button, payload) {
                const buttons = row.querySelectorAll('.adjustment-save');
                buttons.forEach((item) => item.disabled = true);
                button.textContent = 'Đang lưu...';

                try {
                    const response = await fetch(applyUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify(payload),
                    });
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok || !data.ok) {
                        throw new Error(data.message || 'Không thể lưu phương án này.');
                    }

                    row.classList.add('is-resolved');
                    renderStatus(row, 'Đã lưu phương án.', 'success');

                    window.setTimeout(() => {
                        row.classList.add('is-hiding');

                        window.setTimeout(() => {
                            row.remove();
                        }, 320);
                    }, 3500);
                } catch (error) {
                    buttons.forEach((item) => item.disabled = false);
                    button.textContent = 'Lưu';
                    renderStatus(row, error.message || 'Không thể lưu phương án này.', 'error');
                }
            }
        });
    </script>
@endpush
