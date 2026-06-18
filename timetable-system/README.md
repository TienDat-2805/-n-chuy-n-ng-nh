# Timetable System

Ứng dụng web hỗ trợ import dữ liệu thời khóa biểu từ Excel, kiểm tra xung đột lịch và xuất kết quả ra file Excel.

## 1. Yêu cầu

- PHP >= 8.2
- Composer
- Node.js và npm
- SQLite hoặc MySQL
- Git

PHP extension cần bật:

- `pdo`
- `pdo_sqlite` hoặc `pdo_mysql`
- `mbstring`
- `openssl`
- `fileinfo`
- `zip`
- `xml`
- `gd`

## 2. Cài đặt

Clone project:

```bash
git clone <repository-url>
cd <repository-folder>/timetable-system
```

Cài thư viện:

```bash
composer install
npm install
```

Tạo file `.env`:

```bash
cp .env.example .env
```

Windows PowerShell:

```powershell
copy .env.example .env
```

Tạo app key:

```bash
php artisan key:generate
```

## 3. Cấu hình database

### SQLite

Tạo file database:

```bash
touch database/database.sqlite
```

Windows PowerShell:

```powershell
New-Item -ItemType File -Force database\database.sqlite
```

Cấu hình `.env`:

```env
DB_CONNECTION=sqlite
```

Chạy migration:

```bash
php artisan migrate
```

### MySQL

Tạo database:

```sql
CREATE DATABASE timetable_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Cấu hình `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=timetable_system
DB_USERNAME=root
DB_PASSWORD=
```

Chạy migration:

```bash
php artisan migrate
```

## 4. Chạy ứng dụng

Build frontend:

```bash
npm run build
```

Chạy server:

```bash
php artisan serve
```

Mở trình duyệt:

```text
http://127.0.0.1:8000
```

Tạo tài khoản tại:

```text
http://127.0.0.1:8000/register
```

## 5. Sử dụng

Các màn hình chính:

| Đường dẫn | Chức năng |
|---|---|
| `/imports` | Import file Excel |
| `/subjects` | Xem danh sách học phần |
| `/sections` | Xem danh sách lớp học phần |
| `/timetable` | Xem thời khóa biểu |
| `/conflicts` | Kiểm tra và xử lý xung đột |
| `/exports/timetable` | Xuất thời khóa biểu ra Excel |

Luồng sử dụng cơ bản:

1. Đăng ký hoặc đăng nhập.
2. Vào `/imports` và upload file Excel `.xlsx` hoặc `.xls`.
3. Xem dữ liệu đã import ở `/subjects`, `/sections`, `/timetable`.
4. Vào `/conflicts` để kiểm tra xung đột lịch.
5. Vào `/exports/timetable` để tải file Excel kết quả.

Nếu repository có thư mục dữ liệu mẫu, có thể thử các file trong:

```text
../input_mau
```

## 6. Lưu ý về file Excel

File Excel nên có các thông tin chính:

- Mã học phần hoặc tên học phần
- Mã lớp học phần
- Giảng viên
- Thứ học
- Tiết học hoặc khoảng tiết
- Phòng học nếu có

Hệ thống xử lý lịch theo thứ và số tiết, ví dụ:

```text
Thứ 2, tiết 3-5
```

Mỗi lần import, hệ thống sẽ làm mới dữ liệu import cũ trước khi nạp dữ liệu mới.

## 7. Lệnh hữu ích

```bash
php artisan serve
php artisan migrate
php artisan migrate:fresh
php artisan optimize:clear
npm run build
php artisan test
```

