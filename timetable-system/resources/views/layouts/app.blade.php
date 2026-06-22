<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Hệ thống lập thời khóa biểu')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
    <header class="app-header">
        <a class="brand" href="{{ route('imports.index') }}">
            <span>Time</span><span>Table</span>
        </a>

        <div class="header-actions">
            <div class="account-chip">
                <div class="avatar">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</div>
                <div>
                    <div class="account-name">{{ auth()->user()->name }}</div>
                    <div class="account-role">Người dùng hệ thống</div>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="logout-button" type="submit">Đăng xuất</button>
            </form>
        </div>
    </header>

    <aside class="sider">
        <nav class="nav">
            <a class="{{ request()->routeIs('imports.*') ? 'active' : '' }}" href="{{ route('imports.index') }}">
                <span class="nav-icon">TK</span>
                Nhập & xếp lịch
            </a>
            <a class="{{ request()->routeIs('subjects.*') ? 'active' : '' }}" href="{{ route('subjects.index') }}">
                <span class="nav-icon">MH</span>
                Môn học
            </a>
            <a class="{{ request()->routeIs('lecturers.*') ? 'active' : '' }}" href="{{ route('lecturers.index') }}">
                <span class="nav-icon">GV</span>
                Giảng viên
            </a>
            <a class="{{ request()->routeIs('rooms.*') ? 'active' : '' }}" href="{{ route('rooms.index') }}">
                <span class="nav-icon">P</span>
                Phòng học
            </a>
            <a class="{{ request()->routeIs('conflicts.*') ? 'active' : '' }}" href="{{ route('conflicts.index') }}">
                <span class="nav-icon">KT</span>
                Kiểm tra lịch
            </a>
        </nav>
    </aside>

    <main class="main">
        <div class="page-head">
            <div>
                <div class="eyebrow">Hệ thống lập thời khóa biểu</div>
                <h1>@yield('page_title')</h1>
            </div>
        </div>

        <div class="quick-workflow">
            <a class="{{ request()->routeIs('imports.*') ? 'active' : '' }}" href="{{ route('imports.index') }}">Nhập & xếp lịch</a>
            <a class="{{ request()->routeIs('subjects.*') ? 'active' : '' }}" href="{{ route('subjects.index') }}">Môn học</a>
            <a class="{{ request()->routeIs('lecturers.*') ? 'active' : '' }}" href="{{ route('lecturers.index') }}">Giảng viên</a>
            <a class="{{ request()->routeIs('rooms.*') ? 'active' : '' }}" href="{{ route('rooms.index') }}">Phòng học</a>
            <a class="{{ request()->routeIs('conflicts.*') ? 'active' : '' }}" href="{{ route('conflicts.index') }}">Kiểm tra lịch</a>
        </div>

        <div class="container @yield('container_class')">
            @include('partials.flash')

            @yield('content')
        </div>
    </main>
@else
    @yield('content')
@endauth
<script src="{{ asset('js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
