<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('filament.admin.pages.dashboard');
});

// Showcase design system Livewire — acuan visual komponen <x-ui.*>.
Route::view('/styleguide', 'styleguide')->name('styleguide');
