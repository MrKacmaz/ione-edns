<?php

use App\Events\NotificationStatusChanged;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

// Lazily: a test that never touches the database does not run the migrations.
pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Build a valid request payload for a single SMS notification.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function smsNotificationPayload(array $overrides = []): array
{
    return [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'content' => 'Your order has shipped.',
        ...$overrides,
    ];
}

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
 * Get the notification statuses announced over WebSockets, in the order their broadcasts were queued.
 *
 * Needs a faked queue. It sees both single announcements and those pushed in bulk.
 *
 * @return list<array{id: string, status: string}>
 */
function announcedStatuses(): array
{
    return Queue::pushed(BroadcastEvent::class)
        ->filter(fn (BroadcastEvent $job): bool => $job->event instanceof NotificationStatusChanged)
        ->map(fn (BroadcastEvent $job): array => ['id' => $job->event->notification['id'], 'status' => $job->event->notification['status']])
        ->values()
        ->all();
}
