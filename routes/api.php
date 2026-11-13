<?php

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php under the "api/v1" prefix, the "api.v1."
| name prefix and the "api" + "auth:sanctum" middleware.
|
*/

use App\Http\Controllers\CancelNotificationBatchController;
use App\Http\Controllers\CancelNotificationController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NotificationBatchController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationTemplateController;
use App\Http\Controllers\RetryNotificationController;
use App\Http\Middleware\EnsureRequestIsIdempotent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('user', static function (Request $request) {
    return $request->user();
})->name('user');

Route::get('metrics', MetricsController::class)
    ->name('metrics');

Route::get('notifications', [NotificationController::class, 'index'])
    ->name('notifications.index');

Route::post('notifications', [NotificationController::class, 'store'])
    ->middleware(EnsureRequestIsIdempotent::class)
    ->name('notifications.store');

Route::post('notifications/batch', [NotificationBatchController::class, 'store'])
    ->middleware(EnsureRequestIsIdempotent::class)
    ->name('notification-batches.store');

Route::get('notifications/{notification}', [NotificationController::class, 'show'])
    ->whereUuid('notification')
    ->name('notifications.show');

Route::post('notifications/{notification}/cancel', CancelNotificationController::class)
    ->whereUuid('notification')
    ->name('notifications.cancel');

Route::post('notifications/{notification}/retry', RetryNotificationController::class)
    ->whereUuid('notification')
    ->name('notifications.retry');

Route::get('batches/{batch}', [NotificationBatchController::class, 'show'])
    ->whereUuid('batch')
    ->name('notification-batches.show');

Route::post('batches/{batch}/cancel', CancelNotificationBatchController::class)
    ->whereUuid('batch')
    ->name('notification-batches.cancel');

Route::get('templates', [NotificationTemplateController::class, 'index'])
    ->name('notification-templates.index');

Route::post('templates', [NotificationTemplateController::class, 'store'])
    ->name('notification-templates.store');

Route::get('templates/{template}', [NotificationTemplateController::class, 'show'])
    ->whereUuid('template')
    ->name('notification-templates.show');

Route::patch('templates/{template}', [NotificationTemplateController::class, 'update'])
    ->whereUuid('template')
    ->name('notification-templates.update');

Route::delete('templates/{template}', [NotificationTemplateController::class, 'destroy'])
    ->whereUuid('template')
    ->name('notification-templates.destroy');
