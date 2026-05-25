<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Hệ thống lập thời khóa biểu')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="container @yield('container_class')">
    <h1>@yield('page_title')</h1>

    <div class="nav">
        <a href="{{ route('imports.index') }}">Import Excel</a>
        <a href="{{ route('sections.index') }}">Lớp học phần</a>
        <a href="{{ route('timetable.index') }}">Thời khóa biểu</a>
        <a href="{{ route('conflicts.index') }}">Xung đột lịch</a>
        <a href="{{ route('exports.timetable') }}">Xuất Excel</a>
    </div>

    @if(session('success'))
        <div class="alert-success">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert-error">
            {{ session('error') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert-error">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>