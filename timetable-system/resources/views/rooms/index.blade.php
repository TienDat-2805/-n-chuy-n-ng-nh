@extends('layouts.app')

@section('title', 'Quản lý phòng học')
@section('page_title', 'Phòng học')

@section('content')
    <section class="room-hero">
        <div>
            <div class="panel-kicker">Nguồn lực xếp lịch</div>
            <h2>Danh mục phòng học</h2>
            <p>
                Phòng học được quản lý theo từng cơ sở để dễ rà soát.
                Chọn một thẻ cơ sở bên dưới, hệ thống chỉ hiển thị các phòng thuộc cơ sở đó.
            </p>
        </div>

        <form class="room-create-form" action="{{ route('rooms.store') }}" method="POST" data-async-room-form>
            @csrf
            <input type="text" name="name" placeholder="Tên phòng" required>
            <select name="campus" required>
                @foreach($campuses as $value => $label)
                    <option value="{{ $value }}" @selected($selectedCampus === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn" type="submit">Thêm phòng</button>
            <span class="async-form-status" data-async-status aria-live="polite"></span>
        </form>
    </section>

    <section class="campus-tabs">
        @foreach($campusOptions as $campus)
            <a
                class="campus-tab {{ $selectedCampus === $campus['value'] ? 'active' : '' }}"
                href="{{ route('rooms.index', array_merge(request()->except('campus'), ['campus' => $campus['value']])) }}"
                data-ajax-link
            >
                <span>{{ $campus['label'] }}</span>
                <strong>{{ $campus['count'] }}</strong>
            </a>
        @endforeach
    </section>

    <form class="room-filter" method="GET" action="{{ route('rooms.index') }}" data-ajax-form data-live-search data-auto-submit>
        <input type="hidden" name="campus" value="{{ $selectedCampus }}">
        <input type="text" name="keyword" value="{{ $keyword }}" placeholder="Tìm phòng trong {{ $selectedCampusLabel }}...">

        <select name="usage">
            <option value="all" @selected($selectedUsage === 'all')>Tất cả trạng thái</option>
            <option value="used" @selected($selectedUsage === 'used')>Đã có lịch</option>
            <option value="unused" @selected($selectedUsage === 'unused')>Chưa có lịch</option>
        </select>

        <button class="btn" type="submit">Lọc phòng</button>
        <a class="btn btn-gray" href="{{ route('rooms.index', ['campus' => $selectedCampus]) }}" data-ajax-link>Làm mới</a>
    </form>

    <section class="room-resource-list">
        @forelse($rooms as $room)
            <article class="room-resource-card">
                <div class="room-resource-head">
                    <div>
                        <div class="room-title-line">
                            <strong>{{ $room->name }}</strong>
                        </div>
                        <small>{{ $room->meetings_count }} lịch học</small>
                    </div>
                </div>

                <details class="room-schedule-panel">
                    <summary>
                        {{ $room->meetings_count > 0 ? 'Xem môn đang có lịch trong phòng' : 'Chưa có môn nào trong phòng này' }}
                    </summary>

                    @if($room->meetings_count > 0)
                        <div class="room-schedule-list">
                            @foreach($room->meetings as $meeting)
                                <div class="room-schedule-item">
                                    <strong>{{ $meeting->displaySectionCode() }}</strong>
                                    <span>{{ $meeting->section?->subject?->name ?? 'Không rõ môn học' }}</span>
                                    <small>
                                        Thứ {{ $meeting->day_of_week }},
                                        tiết {{ $meeting->start_period }}-{{ min($meeting->end_period, 12) }}
                                        @if($meeting->displayLecturerName())
                                            · GV: {{ $meeting->displayLecturerName() }}
                                        @endif
                                    </small>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </details>

                <details class="room-edit-panel">
                    <summary>Sửa thông tin</summary>

                    <form action="{{ route('rooms.update', $room) }}" method="POST" data-async-room-form>
                        @csrf
                        @method('PUT')

                        <div class="room-edit-grid compact-room-edit">
                            <input type="text" name="name" value="{{ $room->name }}" placeholder="Tên phòng" required>
                            <select name="campus" required>
                                @foreach($campuses as $value => $label)
                                    <option value="{{ $value }}" @selected($room->campus === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <button class="btn btn-green room-update-button" type="submit">Cập nhật phòng</button>
                        <span class="async-form-status" data-async-status aria-live="polite"></span>
                    </form>
                </details>

                @if($room->meetings_count === 0)
                    <form class="inline-delete" action="{{ route('rooms.destroy', $room) }}" method="POST" data-async-room-form data-confirm-message="Xóa phòng học này?">
                        @csrf
                        @method('DELETE')
                        <button type="submit">Xóa phòng</button>
                        <span class="async-form-status" data-async-status aria-live="polite"></span>
                    </form>
                @else
                    <div class="room-locked-note">Phòng đang được dùng trong thời khóa biểu nên không thể xóa.</div>
                @endif
            </article>
        @empty
            <div class="empty-state">Không tìm thấy phòng học phù hợp tại {{ $selectedCampusLabel }}.</div>
        @endforelse
    </section>
@endsection
