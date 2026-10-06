<?php

use App\Http\Controllers\DropController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DropController::class, 'index'])->name('home');
Route::get('/d/{id}', [DropController::class, 'show'])->name('drop.show');
Route::get('/d/{id}/delete', [DropController::class, 'deleteForm'])->name('drop.delete.form');
Route::get('/about/security', [DropController::class, 'security'])->name('security');
Route::get('/health', [DropController::class, 'health'])->name('health');
