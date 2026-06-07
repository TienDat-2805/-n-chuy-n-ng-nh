@extends('layouts.app')

@section('title', 'Đăng nhập')

@section('content')
    <main class="account-page">
        <section class="account-card">
            <div class="account-logo">
                <span>Time</span><span>Table</span>
            </div>

            <h1>Đăng nhập</h1>
            <p>Truy cập khu vực quản trị để import dữ liệu và tạo thời khóa biểu.</p>

            @include('partials.flash')

            <form class="account-form" method="POST" action="{{ route('login.store') }}">
                @csrf

                <label>
                    <span>Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autofocus>
                </label>

                <label>
                    <span>Mật khẩu</span>
                    <input type="password" name="password" required>
                </label>

                <div class="form-meta">
                    <label class="check-line">
                        <input type="checkbox" name="remember" value="1">
                        <span>Ghi nhớ đăng nhập</span>
                    </label>
                </div>

                <button class="account-button" type="submit">Đăng nhập</button>
            </form>

            <div class="account-more">
                <span>Chưa có tài khoản?</span>
                <a href="{{ route('register') }}">Đăng ký</a>
            </div>
        </section>
    </main>
@endsection
