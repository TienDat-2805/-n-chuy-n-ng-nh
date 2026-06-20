<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ConflictController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LecturerController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('imports.index');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::post('/schedule/generate', [ScheduleController::class, 'generate'])->name('schedule.generate');

    Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
    Route::post('/subjects/{subject}/lecturers', [SubjectController::class, 'attachLecturer'])->name('subjects.lecturers.attach');
    Route::patch('/subjects/lecturers/{lecturer}/availability', [SubjectController::class, 'updateLecturerAvailability'])->name('subjects.lecturers.availability');

    Route::get('/lecturers', [LecturerController::class, 'index'])->name('lecturers.index');
    Route::post('/lecturers', [LecturerController::class, 'store'])->name('lecturers.store');
    Route::put('/lecturers/{lecturer}', [LecturerController::class, 'update'])->name('lecturers.update');
    Route::delete('/lecturers/{lecturer}', [LecturerController::class, 'destroy'])->name('lecturers.destroy');

    Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
    Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');

    Route::get('/sections', [SectionController::class, 'index'])->name('sections.index');
    Route::get('/sections/{section}', [SectionController::class, 'show'])->name('sections.show');

    Route::get('/timetable', [TimetableController::class, 'index'])->name('timetable.index');

    Route::get('/conflicts', [ConflictController::class, 'index'])->name('conflicts.index');
    Route::post('/conflicts/check', [ConflictController::class, 'check'])->name('conflicts.check');
    Route::post('/conflicts/auto-schedule', [ConflictController::class, 'autoSchedule'])->name('conflicts.auto-schedule');
    Route::post('/conflicts/apply', [ConflictController::class, 'apply'])->name('conflicts.apply');
});
