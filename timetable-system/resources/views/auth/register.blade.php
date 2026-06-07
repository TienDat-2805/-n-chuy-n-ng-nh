@extends('layouts.app')

@section('title', 'Đăng ký')

@section('content')
    <main class="account-page">
        <section class="account-card">
            <div class="account-logo">
                <span>Time</span><span>Table</span>
            </div>

            <h1>Tạo tài khoản</h1>
            <p>Đăng ký tài khoản để quản lý import, môn học và lịch học.</p>

            @include('partials.flash')

            <form class="account-form" method="POST" action="{{ route('register.store') }}">
                @csrf

                <label>
                    <span>Họ tên</span>
                    <input type="text" name="name" value="{{ old('name') }}" required autofocus>
                </label>

                <label>
                    <span>Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" required>
                </label>

                <label>
                    <span>Mật khẩu</span>
                    <input type="password" name="password" required>
                </label>

                <label>
                    <span>Nhập lại mật khẩu</span>
                    <input type="password" name="password_confirmation" required>
                </label>

                <button class="account-button" type="submit">Tạo tài khoản</button>
            </form>

            <div class="account-more">
                <span>Đã có tài khoản?</span>
                <a href="{{ route('login') }}">Đăng nhập</a>
            </div>
        </section>
    </main>
@endsection
