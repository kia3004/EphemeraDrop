<?php

use App\Http\Controllers\DropController;
use Illuminate\Support\Facades\Route;

Route::post('/drops', [DropController::class, 'create'])->middleware('throttle:create-drop')->name('drops.store');
Route::put('/drops/{id}/chunks/{index}', [DropController::class, 'uploadChunk'])->middleware('throttle:chunk-upload')->whereNumber('index')->name('drops.chunks.upload');
Route::post('/drops/{id}/finalize', [DropController::class, 'finalize'])->name('drops.finalize');
Route::get('/drops/{id}/status', [DropController::class, 'status'])->name('drops.status');
Route::post('/drops/{id}/claim', [DropController::class, 'claim'])->middleware('throttle:claim')->name('drops.claim');
Route::get('/drops/{id}/chunks/{index}', [DropController::class, 'downloadChunk'])->middleware('signed')->whereNumber('index')->name('drops.chunks.download');
Route::delete('/drops/{id}', [DropController::class, 'delete'])->name('drops.delete');
