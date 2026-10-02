<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'auth.session', 'verified', 'onboarded'])->group(function () {
    Route::livewire('/', 'pages::domains.index')->name('domains.index');
    Route::livewire('/domains/{domain}', 'pages::domains.show')->name('domains.show');
});

// Reachable before verification: onboarding starts by confirming your email, and Settings lets you fix the address.
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::livewire('/welcome', 'pages::onboarding')->name('onboarding');
    Route::livewire('/settings', 'pages::settings')->name('settings');
});
