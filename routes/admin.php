<?php

use App\Http\Controllers\Admin\CompetitionPdfController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('competitions', 'pages::admin.competitions.index')->name('competitions.index');
    Route::livewire('competitions/{competition}', 'pages::admin.competitions.show')->name('competitions.show');
    Route::livewire('competitions/{competition}/categories', 'pages::admin.competitions.categories')->name('competitions.categories');
    Route::livewire('competitions/{competition}/entries', 'pages::admin.competitions.entries')->name('competitions.entries');
    Route::livewire('competitions/{competition}/draw', 'pages::admin.competitions.draw')->name('competitions.draw');
    Route::livewire('competitions/{competition}/categories/{category}/bracket', 'pages::admin.competitions.bracket')->name('competitions.bracket');
    Route::livewire('competitions/{competition}/timetable', 'pages::admin.competitions.timetable')->name('competitions.timetable');
    Route::get('competitions/{competition}/categories/{category}/bracket.pdf', [CompetitionPdfController::class, 'bracket'])->name('competitions.bracket.pdf');
    Route::get('competitions/{competition}/draw.pdf', [CompetitionPdfController::class, 'draw'])->name('competitions.draw.pdf');
    Route::get('competitions/{competition}/timetable.pdf', [CompetitionPdfController::class, 'timetable'])->name('competitions.timetable.pdf');
    Route::livewire('clubs', 'pages::admin.clubs.index')->name('clubs.index');
    Route::livewire('competitors', 'pages::admin.competitors.index')->name('competitors.index');
    Route::livewire('users', 'pages::admin.users.index')->name('users.index');
});
