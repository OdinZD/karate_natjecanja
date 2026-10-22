<?php

use App\Models\Competition;
use Illuminate\Support\Facades\Route;

Route::bind('publicCompetition', fn (string $slug) => Competition::query()->visible()->where('slug', $slug)->firstOrFail());

Route::livewire('/', 'pages::public.competitions.index')->name('home');

Route::prefix('competitions/{publicCompetition:slug}')->name('public.competitions.')->group(function () {
    Route::livewire('/', 'pages::public.competitions.show')->name('show');
    Route::livewire('categories', 'pages::public.competitions.categories')->name('categories');
    Route::livewire('competitors', 'pages::public.competitions.competitors')->name('competitors');
    Route::livewire('draw', 'pages::public.competitions.draw')->name('draw');
    Route::livewire('draw/{category}', 'pages::public.competitions.bracket')->name('bracket');
    Route::livewire('timetable', 'pages::public.competitions.timetable')->name('timetable');
    Route::livewire('results', 'pages::public.competitions.results')->name('results');
    Route::livewire('standings', 'pages::public.competitions.standings')->name('standings');
});
