<?php

use App\Enums\HealthCheckStatus;
use App\Services\Observability\HealthChecks\DatabaseHealthCheck;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('passes while the database answers queries', function () {
    $result = app(DatabaseHealthCheck::class)->check();

    expect($result->status)->toBe(HealthCheckStatus::Ok)
        ->and($result->message)->toBeNull();
});

test('throws when no connection to the database can be opened, which counts as a failed check', function () {
    config(['database.connections.missing' => [
        ...config('database.connections.pgsql'),
        'database' => 'edns_health_check_missing_database',
    ]]);
    $check = app(DatabaseHealthCheck::class);

    DB::usingConnection('missing', fn () => $check->check());
})->throws(QueryException::class, 'edns_health_check_missing_database');
