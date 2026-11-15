<?php

use App\Http\Controllers\AuthenticatedSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardNotificationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('dashboard', DashboardController::class)->middleware('can:operate')->name('dashboard');
    // Shares the API's request budget, so the form is no way around it.
    Route::post('dashboard/notifications', [DashboardNotificationController::class, 'store'])
        ->middleware(['can:operate', 'throttle:api'])
        ->name('dashboard.notifications.store');
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
