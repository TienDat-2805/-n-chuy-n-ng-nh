<?php

use App\Http\Controllers\ConflictController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\TimetableController;
use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('imports.index');
});

Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');

Route::get('/sections', [SectionController::class, 'index'])->name('sections.index');
Route::get('/sections/{section}', [SectionController::class, 'show'])->name('sections.show');

Route::get('/timetable', [TimetableController::class, 'index'])->name('timetable.index');

Route::get('/conflicts', [ConflictController::class, 'index'])->name('conflicts.index');
Route::post('/conflicts/check', [ConflictController::class, 'check'])->name('conflicts.check');

Route::get('/exports/timetable', [ExportController::class, 'timetable'])->name('exports.timetable');