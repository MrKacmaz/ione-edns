<?php

/**
 * A stand-in for the webhook.site provider (the provider service in docker-compose.yml),
 * run by PHP's built-in web server: php -S 0.0.0.0:80 server.php
 *
 * Deliveries are answered the way the real provider answers them:
 *
 *   POST /  {"to": "...", "channel": "sms", "content": "..."}
 *   → 202   {"messageId": "...", "status": "accepted", "timestamp": "..."}
 *
 * Like a real provider it honours the Idempotency-Key header: a key it already
 * accepted gets the same messageId again and is counted as a duplicate.
 *
 * Faults for every delivery, changed while the stack runs:
 *
 *   GET    /__control  the current settings
 *   PUT    /__control  {"failure_rate": 0.3, "status": 503, "latency_ms": 200, "retry_after": 5}
 *   DELETE /__control  healthy again
 *
 * Faults for one message, written anywhere into its content:
 *
 *   [fail:503]        answer 503 to every attempt; a 2xx status answers without a messageId
 *   [fail:503*2]      answer 503 to the first two attempts, then accept the message
 *   [slow:1500]       wait 1.5 seconds before answering; longer than the gateway timeout is a timeout
 *   [retry-after:30]  add "Retry-After: 30" to a failure
 *
 * What it received:
 *
 *   GET    /__requests  counters, the last deliveries, and the deliveries per channel and
 *                       second over the last ten minutes with each channel's peak second
 *   DELETE /__requests  forget them and the accepted keys
 *
 * The state lives in files guarded by flock, so the server's worker processes
 * (PHP_CLI_SERVER_WORKERS) share it; it is lost when the container restarts.
 */
const DEFAULT_SETTINGS = ['failure_rate' => 0.0, 'status' => 503, 'latency_ms' => 0, 'retry_after' => null];

const RECENT_DELIVERIES = 50;

const MAX_LATENCY_MS = 60_000;

const PER_SECOND_HISTORY_SECONDS = 600;

$stateDirectory = getenv('MOCK_PROVIDER_STATE_DIRECTORY') ?: sys_get_temp_dir().'/edns-mock-provider';

define('STATE_DIRECTORY', $stateDirectory);

if (! is_dir(STATE_DIRECTORY.'/keys')) {
    // Another worker may create it at the same moment.
    @mkdir(STATE_DIRECTORY.'/keys', 0777, true);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

match (true) {
    $path === '/__control' => control($method),
    $path === '/__requests' => requests($method),
    $method === 'POST' => deliver(),
    default => respond(405, ['error' => 'Deliveries are POST requests; see /__control and /__requests.']),
};

/**
 * Show, change or reset the faults applied to every delivery.
 */
function control(string $method): void
{
    if ($method === 'DELETE') {
        @unlink(STATE_DIRECTORY.'/settings.json');
        respond(200, DEFAULT_SETTINGS);

        return;
    }

    if ($method === 'GET') {
        respond(200, settings());

        return;
    }

    if ($method !== 'PUT' && $method !== 'PATCH') {
        respond(405, ['error' => 'Use GET, PUT or DELETE.']);

        return;
    }

    $changes = json_decode((string) file_get_contents('php://input'), true);
    $error = is_array($changes) ? invalidSetting($changes) : 'Expected a JSON object.';

    if ($error !== null) {
        respond(422, ['error' => $error]);

        return;
    }

    respond(200, updateState('settings', function (?array &$settings) use ($changes): array {
        $settings = [...($settings ?? DEFAULT_SETTINGS), ...$changes];

        return $settings;
    }));
}

/**
 * Describe what is wrong with the requested settings, or null when they are valid.
 *
 * @param  array<mixed>  $changes
 */
function invalidSetting(array $changes): ?string
{
    $unknown = array_diff(array_keys($changes), array_keys(DEFAULT_SETTINGS));

    return match (true) {
        $unknown !== [] => 'Unknown settings: '.implode(', ', $unknown).'. Known: '.implode(', ', array_keys(DEFAULT_SETTINGS)).'.',
        array_key_exists('failure_rate', $changes) && (! is_numeric($changes['failure_rate']) || $changes['failure_rate'] < 0 || $changes['failure_rate'] > 1) => 'failure_rate must be between 0 and 1.',
        array_key_exists('status', $changes) && (! is_int($changes['status']) || $changes['status'] < 200 || $changes['status'] > 599) => 'status must be an HTTP status from 200 to 599.',
        array_key_exists('latency_ms', $changes) && (! is_int($changes['latency_ms']) || $changes['latency_ms'] < 0 || $changes['latency_ms'] > MAX_LATENCY_MS) => 'latency_ms must be from 0 to '.MAX_LATENCY_MS.'.',
        array_key_exists('retry_after', $changes) && $changes['retry_after'] !== null && (! is_int($changes['retry_after']) || $changes['retry_after'] < 0) => 'retry_after must be null or a number of seconds.',
        default => null,
    };
}

/**
 * Show or forget what the provider received.
 */
function requests(string $method): void
{
    if ($method === 'DELETE') {
        @unlink(STATE_DIRECTORY.'/requests.json');
        array_map('unlink', glob(STATE_DIRECTORY.'/keys/*.json') ?: []);
        respond(200, [...emptyLog(), 'failed' => (object) [], 'per_second' => (object) [], 'peak_per_second' => (object) []]);

        return;
    }

    if ($method !== 'GET') {
        respond(405, ['error' => 'Use GET or DELETE.']);

        return;
    }

    $log = readState('requests') ?? emptyLog();
    $perSecond = $log['per_second'] ?? [];

    // Keyed by status or channel, so objects even while empty.
    respond(200, [
        ...$log,
        'failed' => (object) $log['failed'],
        'per_second' => (object) array_map(fn (array $seconds): object => (object) $seconds, $perSecond),
        'peak_per_second' => (object) array_map(fn (array $seconds): int => max($seconds), $perSecond),
    ]);
}

/**
 * Answer one delivery: accept it, replay an accepted one, or inject a fault.
 */
function deliver(): void
{
    $message = json_decode((string) file_get_contents('php://input'), true);

    if (! is_array($message) || ! is_string($message['to'] ?? null) || ! is_string($message['channel'] ?? null) || ! is_string($message['content'] ?? null)) {
        record(400, null, false, null, []);
        respond(400, ['error' => 'Expected a JSON body with to, channel and content.']);

        return;
    }

    $settings = settings();
    $content = $message['content'];
    $latencyMs = min(MAX_LATENCY_MS, (int) (marker('slow', $content) ?? $settings['latency_ms']));

    if ($latencyMs > 0) {
        usleep($latencyMs * 1000);
    }

    $key = ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '') !== '' ? $_SERVER['HTTP_IDEMPOTENCY_KEY'] : null;

    // Deciding under the key's lock keeps two concurrent attempts of one message from both being accepted.
    $decide = function (?array &$delivery) use ($content, $settings): array {
        $delivery ??= ['attempts' => 0, 'message_id' => null];
        $delivery['attempts']++;

        if ($delivery['message_id'] !== null) {
            return [202, $delivery['message_id'], true, $delivery['attempts']];
        }

        $status = injectedFailure($content, $settings, $delivery['attempts']);

        if ($status === null) {
            $delivery['message_id'] = uuid();
        }

        return [$status ?? 202, $delivery['message_id'], false, $delivery['attempts']];
    };

    if ($key === null) {
        $delivery = null;
        [$status, $messageId, $duplicate, $attempt] = $decide($delivery);
    } else {
        [$status, $messageId, $duplicate, $attempt] = updateState('keys/'.sha1($key), $decide);
    }

    record($status, $messageId, $duplicate, $attempt, $message);

    if ($messageId !== null) {
        respond($status, ['messageId' => $messageId, 'status' => 'accepted', 'timestamp' => now()]);

        return;
    }

    $retryAfter = marker('retry-after', $content) ?? $settings['retry_after'];

    // A 2xx without a messageId is an answer the gateway cannot count as accepted.
    respond(
        $status,
        $status < 300 ? ['status' => 'accepted', 'timestamp' => now()] : ['error' => "Injected failure: HTTP {$status}."],
        $status >= 300 && $retryAfter !== null ? ['Retry-After' => (string) $retryAfter] : [],
    );
}

/**
 * Get the status of the fault this attempt runs into, or null when it is accepted.
 *
 * @param  array{failure_rate: float|int, status: int, latency_ms: int, retry_after: ?int}  $settings
 */
function injectedFailure(string $content, array $settings, int $attempt): ?int
{
    if (preg_match('/\[fail:(\d{3})(?:\*(\d+))?\]/', $content, $match) === 1) {
        $failingAttempts = isset($match[2]) ? (int) $match[2] : PHP_INT_MAX;

        return $attempt <= $failingAttempts ? (int) $match[1] : null;
    }

    if ($settings['failure_rate'] > 0 && mt_rand() / mt_getrandmax() < $settings['failure_rate']) {
        return $settings['status'];
    }

    return null;
}

/**
 * Read the number of a "[name:number]" marker in the content.
 */
function marker(string $name, string $content): ?int
{
    return preg_match('/\['.preg_quote($name, '/').':(\d+)\]/', $content, $match) === 1 ? (int) $match[1] : null;
}

/**
 * Count one delivery and keep it among the recent ones; it is also logged to stderr.
 *
 * @param  array<mixed>  $message
 */
function record(int $status, ?string $messageId, bool $duplicate, ?int $attempt, array $message): void
{
    $entry = [
        'received_at' => now(),
        'status' => $status,
        'message_id' => $messageId,
        'duplicate' => $duplicate,
        'attempt' => $attempt,
        'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
        'correlation_id' => $_SERVER['HTTP_X_CORRELATION_ID'] ?? null,
        'channel' => $message['channel'] ?? null,
        'to' => $message['to'] ?? null,
    ];

    updateState('requests', function (?array &$log) use ($entry, $status, $messageId, $duplicate): null {
        $log ??= emptyLog();
        $log['received']++;

        // Every call counts here, as the rate limit applies to every attempt.
        $channel = (string) ($entry['channel'] ?? 'unknown');
        $second = time();
        $log['per_second'][$channel][$second] = ($log['per_second'][$channel][$second] ?? 0) + 1;
        $log['per_second'][$channel] = array_filter(
            $log['per_second'][$channel],
            fn (int $count, int|string $at): bool => (int) $at > $second - PER_SECOND_HISTORY_SECONDS,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($duplicate) {
            $log['duplicates']++;
        } elseif ($messageId !== null) {
            $log['accepted']++;
        } else {
            $log['failed'][(string) $status] = ($log['failed'][(string) $status] ?? 0) + 1;
        }

        $log['recent'] = array_slice([$entry, ...$log['recent']], 0, RECENT_DELIVERIES);

        return null;
    });

    file_put_contents('php://stderr', json_encode(['message' => 'provider.delivery', ...$entry], JSON_UNESCAPED_SLASHES).PHP_EOL);
}

/**
 * @return array{received: int, accepted: int, duplicates: int, failed: array<string, int>, recent: list<array<string, mixed>>, per_second: array<string, array<int, int>>}
 */
function emptyLog(): array
{
    return ['received' => 0, 'accepted' => 0, 'duplicates' => 0, 'failed' => [], 'recent' => [], 'per_second' => []];
}

/**
 * @return array{failure_rate: float|int, status: int, latency_ms: int, retry_after: ?int}
 */
function settings(): array
{
    return [...DEFAULT_SETTINGS, ...(readState('settings') ?? [])];
}

/**
 * Read a state file under a shared lock.
 *
 * @return array<mixed>|null
 */
function readState(string $name): ?array
{
    $handle = @fopen(STATE_DIRECTORY."/{$name}.json", 'r');

    if ($handle === false) {
        return null;
    }

    flock($handle, LOCK_SH);
    $state = json_decode((string) stream_get_contents($handle), true);
    flock($handle, LOCK_UN);
    fclose($handle);

    return is_array($state) ? $state : null;
}

/**
 * Change a state file under an exclusive lock and return what the change returns.
 *
 * The change receives the current state (null when there is none) by reference.
 */
function updateState(string $name, callable $change): mixed
{
    $handle = fopen(STATE_DIRECTORY."/{$name}.json", 'c+');
    flock($handle, LOCK_EX);

    $state = json_decode((string) stream_get_contents($handle), true);
    $state = is_array($state) ? $state : null;
    $result = $change($state);

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($state, JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

/**
 * @param  array<mixed>  $body
 * @param  array<string, string>  $headers
 */
function respond(int $status, array $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');

    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }

    echo json_encode($body, JSON_UNESCAPED_SLASHES);
}

function now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
}

function uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0F | 0x40);
    $bytes[8] = chr(ord($bytes[8]) & 0x3F | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}
