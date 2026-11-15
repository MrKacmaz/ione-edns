<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

test('writes one JSON object per log entry, carrying the request context', function () {
    $path = storage_path('logs/testing-structured.log');
    File::delete($path);
    config(['logging.channels.single.path' => $path]);
    Context::add('correlation_id', '0f8fad5b-d9cb-469f-a165-70867728950e');

    Log::channel('single')->info('notification.delivery.sent', ['notification_id' => 'abc']);

    $entry = json_decode(trim(File::get($path)), true, flags: JSON_THROW_ON_ERROR);
    File::delete($path);
    expect($entry)
        ->message->toBe('notification.delivery.sent')
        ->level_name->toBe('INFO')
        ->and($entry['context']['notification_id'])->toBe('abc')
        ->and($entry['extra']['correlation_id'])->toBe('0f8fad5b-d9cb-469f-a165-70867728950e');
});

test('writes plain text lines instead of JSON when LOG_FORMAT is line', function () {
    $_SERVER['LOG_FORMAT'] = 'line';

    try {
        $channels = (require config_path('logging.php'))['channels'];
    } finally {
        // A process-wide variable: the next tests must not see it.
        unset($_SERVER['LOG_FORMAT']);
    }

    $path = tempnam(sys_get_temp_dir(), 'edns-log-');
    config(['logging.channels.single' => [...$channels['single'], 'path' => $path]]);

    Log::channel('single')->info('notification.delivery.sent', ['notification_id' => 'abc']);

    $line = File::get($path);
    File::delete($path);
    expect($line)
        ->toStartWith('[')
        ->toContain('] testing.INFO: notification.delivery.sent {"notification_id":"abc"}');
});
