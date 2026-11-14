<?php

use App\Enums\NotificationChannel;
use App\Jobs\Middleware\CheckDeliveryChannelCircuit;
use App\Jobs\Middleware\RateLimitDeliveryChannel;
use App\Jobs\Middleware\ReleaseDuringInfrastructureOutage;
use App\Jobs\SendNotificationJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Build the error the database layer raises for a query, with the given SQLSTATE and message.
 */
function queryException(string $sqlState, string $message): QueryException
{
    // PDO puts the SQLSTATE into the protected $code, which only a subclass can set.
    $pdoException = new class("SQLSTATE[{$sqlState}]: {$message}", $sqlState) extends PDOException
    {
        public function __construct(string $message, string $sqlState)
        {
            parent::__construct($message);
            $this->code = $sqlState;
        }
    };

    return new QueryException('pgsql', 'update "notifications" set "status" = ?', [], $pdoException);
}

/**
 * Run the middleware around a job body that throws the given error.
 */
function runThroughOutageGuard(SendNotificationJob $job, Throwable $error): void
{
    (new ReleaseDuringInfrastructureOutage(Log::getLogger()))->handle($job, function () use ($error): void {
        throw $error;
    });
}

test('runs the job as it is when nothing goes wrong', function () {
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();
    $ran = false;

    (new ReleaseDuringInfrastructureOutage)->handle($job, function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeTrue();
    $job->assertNotReleased();
});

test('waits out a database or Redis outage instead of counting it towards the job\'s tolerated exceptions', function (Throwable $outage) {
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();

    runThroughOutageGuard($job, $outage);

    $job->assertReleased(delay: 10);
})->with([
    'refused database connection' => fn () => queryException('08006', 'connection to server at "postgres", port 5432 failed: Connection refused'),
    'database connection lost mid-query' => fn () => queryException('HY000', 'server closed the connection unexpectedly'),
    'Redis unreachable' => fn () => new RedisException('Connection refused'),
]);

test('waits longer with each further try during a long outage, at most five minutes', function (int $attempts, int $expectedDelay) {
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();
    $job->job->attempts = $attempts;

    runThroughOutageGuard($job, new RedisException('Connection refused'));

    $job->assertReleased(delay: $expectedDelay);
})->with([
    'second try' => [2, 20],
    'fifth try' => [5, 160],
    'sixth try' => [6, 300],
    'fiftieth try' => [50, 300],
]);

test('logs the outage with the notification and the next try', function () {
    Log::spy();
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();

    runThroughOutageGuard($job, new RedisException('Connection refused'));

    Log::shouldHaveReceived('warning')->once()->with('notification.delivery.infrastructure_unavailable', [
        'notification_id' => 'notification-id',
        'error' => 'Connection refused',
        'retry_in_seconds' => 10,
    ]);
});

test('lets every other error count as an exception of the job', function (Throwable $error) {
    $job = (new SendNotificationJob('notification-id', NotificationChannel::Sms))->withFakeQueueInteractions();

    expect(fn () => runThroughOutageGuard($job, $error))->toThrow($error::class);

    $job->assertNotReleased();
})->with([
    'a broken query' => fn () => queryException('42703', 'column "statuss" does not exist'),
    'a unique violation' => fn () => queryException('23505', 'duplicate key value violates unique constraint'),
    'an error in the code' => fn () => new RuntimeException('Something broke'),
]);

test('guards the circuit breaker and the rate limiter too, as both read Redis', function () {
    $middleware = array_map(fn (object $middleware): string => $middleware::class, (new SendNotificationJob('notification-id', NotificationChannel::Sms))->middleware());

    expect(array_slice($middleware, array_search(ReleaseDuringInfrastructureOutage::class, $middleware, true)))
        ->toBe([ReleaseDuringInfrastructureOutage::class, CheckDeliveryChannelCircuit::class, RateLimitDeliveryChannel::class]);
});
