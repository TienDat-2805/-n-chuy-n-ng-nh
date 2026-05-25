@extends('layouts.app')

@section('title', 'Import thời khóa biểu')
@section('page_title', 'Import dữ liệu thời khóa biểu')
@section('container_class', 'container-sm')

@section('content')
    <form action="{{ route('imports.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="form-row">
            <label class="form-label">Chọn file Excel thời khóa biểu:</label>
            <input type="file" name="excel_file" accept=".xlsx,.xls" required>
        </div>

        <button class="btn" type="submit">Import</button>
    </form>

    <hr>

    <div class="note">
        <p><strong>Chức năng hiện tại:</strong></p>
        <ul>
            <li>Nhận file Excel định dạng .xlsx hoặc .xls.</li>
            <li>Tự động nhận diện sheet chứa dữ liệu thời khóa biểu dựa trên các cột như <strong>Mã học phần</strong>, <strong>Tên học phần</strong>, <strong>Mã lớp học phần</strong>.</li>
            <li>Khi import file mới, hệ thống sẽ xóa dữ liệu import cũ để tránh lẫn dữ liệu.</li>
            <li>Lưu học phần, lớp học phần, giảng viên, địa điểm và lịch học vào database.</li>
        </ul>

        <p>
            Sau khi import xong, có thể xem danh sách lớp học phần, thời khóa biểu dạng lưới và kiểm tra xung đột lịch.
        </p>
    </div>
@endsection