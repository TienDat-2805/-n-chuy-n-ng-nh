@extends('layouts.app')

@section('title', 'Quản lý giảng viên')
@section('page_title', 'Giảng viên')

@section('content')
    <section class="management-panel">
        <div>
            <div class="panel-kicker">Dữ liệu giảng viên</div>
            <h2>Thêm giảng viên</h2>
            <p>Giảng viên được import từ file Excel hoặc thêm thủ công tại đây. Ngày và buổi có thể dạy sẽ được dùng khi xếp lịch.</p>
        </div>

        <form class="management-form" action="{{ route('lecturers.store') }}" method="POST">
            @csrf
            <input type="text" name="name" placeholder="Tên giảng viên" required>
            <input type="text" name="department" placeholder="Đơn vị/Bộ môn">
            <input type="email" name="email" placeholder="Email">
            <input type="text" name="phone" placeholder="Số điện thoại">
            <button class="btn" type="submit">Thêm giảng viên</button>
        </form>
    </section>

    <section class="management-list">
        @forelse($lecturers as $lecturer)
            @php
                $selectedSlots = $lecturer->available_slots ?? [];
            @endphp

            <article class="management-card">
                <form action="{{ route('lecturers.update', $lecturer) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="management-card-head">
                        <div>
                            <strong>{{ $lecturer->name }}</strong>
                            <span>{{ $lecturer->sections_count }} lớp học phần</span>
                        </div>
                        <button class="btn btn-green" type="submit">Lưu</button>
                    </div>

                    <div class="management-fields">
                        <input type="text" name="name" value="{{ $lecturer->name }}" placeholder="Tên giảng viên" required>
                        <input type="text" name="department" value="{{ $lecturer->department }}" placeholder="Đơn vị/Bộ môn">
                        <input type="email" name="email" value="{{ $lecturer->email }}" placeholder="Email">
                        <input type="text" name="phone" value="{{ $lecturer->phone }}" placeholder="Số điện thoại">
                    </div>

                    <div class="availability-grid">
                        @foreach($days as $day => $dayLabel)
                            <div class="availability-day">
                                <strong>{{ $dayLabel }}</strong>

                                @foreach($sessions as $session => $sessionLabel)
                                    @php
                                        $slot = $day . '_' . $session;
                                    @endphp
                                    <label class="slot-check">
                                        <input type="checkbox" name="available_slots[]" value="{{ $slot }}" @checked(in_array($slot, $selectedSlots, true))>
                                        <span>{{ $session === 'morning' ? 'Sáng' : 'Chiều' }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </form>

                <form class="inline-delete" action="{{ route('lecturers.destroy', $lecturer) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="submit" onclick="return confirm('Xóa giảng viên này?')">Xóa giảng viên</button>
                </form>
            </article>
        @empty
            <div class="empty-state">Chưa có giảng viên. Hãy import file Excel hoặc thêm thủ công.</div>
        @endforelse
    </section>
@endsection
