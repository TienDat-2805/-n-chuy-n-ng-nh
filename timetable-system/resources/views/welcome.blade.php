<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="0;url={{ route('imports.index') }}">

    <title>{{ config('app.name', 'Hệ thống lập thời khóa biểu') }}</title>
</head>
<body>
    <p>
        Đang chuyển đến
        <a href="{{ route('imports.index') }}">trang import dữ liệu</a>.
    </p>
</body>
</html>
