<?php

use App\Services\Observability\InfrastructureOutageDetector;

test('recognises an error that only means the database or Redis cannot be reached', function (Throwable $error) {
    expect((new InfrastructureOutageDetector)->isOutage($error))->toBeTrue();
})->with([
    'refused database connection' => fn () => queryException('08006', 'connection to server at "postgres", port 5432 failed: Connection refused'),
    'database connection lost mid-query' => fn () => queryException('HY000', 'server closed the connection unexpectedly'),
    'refused connection before any query' => fn () => new PDOException('SQLSTATE[08006] [7] connection to server failed: Connection refused'),
    'Redis unreachable' => fn () => new RedisException('Connection refused'),
]);

test('treats every other error as a fault of its own', function (Throwable $error) {
    expect((new InfrastructureOutageDetector)->isOutage($error))->toBeFalse();
})->with([
    'a broken query' => fn () => queryException('42703', 'column "statuss" does not exist'),
    'a value too long for its column' => fn () => queryException('22001', 'value too long for type character varying(255)'),
    'an error in the code' => fn () => new RuntimeException('Something broke.'),
]);
