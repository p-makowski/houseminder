<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::view('/', 'welcome');

Volt::route('dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Volt::route('appliances', 'pages.appliances.index')
    ->middleware(['auth', 'verified'])
    ->name('appliances.index');

Volt::route('appliances/create', 'pages.appliances.create')
    ->middleware(['auth', 'verified'])
    ->name('appliances.create');

Volt::route('appliances/{appliance}/edit', 'pages.appliances.edit')
    ->middleware(['auth', 'verified'])
    ->name('appliances.edit');

Volt::route('appliances/{appliance}', 'pages.appliances.show')
    ->middleware(['auth', 'verified'])
    ->name('appliances.show');

require __DIR__.'/auth.php';
