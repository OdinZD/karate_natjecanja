<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::livewire('tatami', 'pages::tatami.index')->name('tatami.index');
    Route::livewire('tatami/{tatami}', 'pages::tatami.console')
        ->middleware('can:operate-tatami,tatami')
        ->name('tatami.console');
});

require __DIR__.'/public.php';
require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
