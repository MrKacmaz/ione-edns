<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
