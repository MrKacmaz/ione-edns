<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes retried requests safe by replaying the stored response of a request
 * that was already completed with the same `Idempotency-Key` header.
 *
 * Follows draft-ietf-httpapi-idempotency-key-header: a key reused with a
 * different payload is rejected with 422, and a key whose first request is
 * still in progress is rejected with 409.
 *
 * The lock only answers concurrent retries quickly. Correctness rests on the
 * database: the request runs in one transaction together with storing its
 * response, under the unique (user, key) index. A request that outlasted the lock
 * and lost the race to a retry is rolled back and answered with the retry's
 * response, and a process that dies after the commit cannot leave the work done
 * without the key that would replay it.
 */
class EnsureRequestIsIdempotent
{
    public const string KEY_HEADER = 'Idempotency-Key';

    public const string REPLAYED_HEADER = 'Idempotent-Replayed';

    /**
     * The longest key the `idempotency_keys.key` column can store.
     */
    private const int MAX_KEY_LENGTH = 255;

    /**
     * @param  int  $lockSeconds  How long a request may hold the key before a concurrent retry may take it over.
     * @param  int  $retentionHours  How long a completed response is kept for replay.
     */
    public function __construct(
        #[Config('notifications.idempotency.lock_seconds')] private readonly int $lockSeconds,
        #[Config('notifications.idempotency.retention_hours')] private readonly int $retentionHours,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::KEY_HEADER);
        $user = $request->user();

        if ($key === null || ! $user instanceof User) {
            return $next($request);
        }

        if ($key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
            return response()->json([
                'message' => __('idempotency.invalid_key', ['header' => self::KEY_HEADER, 'max' => self::MAX_KEY_LENGTH]),
            ], Response::HTTP_BAD_REQUEST);
        }

        $lock = Cache::lock(self::lockName($user->id, $key), $this->lockSeconds);

        if (! $lock->get()) {
            return response()->json([
                'message' => __('idempotency.in_progress', ['header' => self::KEY_HEADER]),
            ], Response::HTTP_CONFLICT);
        }

        try {
            $fingerprint = $this->fingerprint($request);

            $completed = $this->completedRequest($user, $key);

            if ($completed !== null) {
                return $this->replayOrReject($completed, $fingerprint);
            }

            try {
                return DB::transaction(function () use ($request, $next, $user, $key, $fingerprint): Response {
                    $response = $next($request);

                    $this->remember($user, $key, $fingerprint, $response);

                    return $response;
                });
            } catch (UniqueConstraintViolationException $exception) {
                // Another request with the same key committed first; this one's work was rolled back.
                $completed = $this->completedRequest($user, $key) ?? throw $exception;

                return $this->replayOrReject($completed, $fingerprint);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Find the stored response of the user's completed request with the key, while it is replayable.
     */
    private function completedRequest(User $user, string $key): ?IdempotencyKey
    {
        return $user->idempotencyKeys()
            ->where('key', $key)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Replay a completed request with the same payload, and refuse the key for any other payload.
     */
    private function replayOrReject(IdempotencyKey $completed, string $fingerprint): Response
    {
        return hash_equals($completed->request_fingerprint, $fingerprint)
            ? $this->replay($completed)
            : response()->json([
                'message' => __('idempotency.payload_mismatch', ['header' => self::KEY_HEADER]),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Get the name of the lock that serializes requests sharing a user's idempotency key.
     */
    public static function lockName(int $userId, string $key): string
    {
        return 'idempotency:'.$userId.':'.hash('sha256', $key);
    }

    /**
     * Store a successful response so retries of the same request can replay it.
     *
     * Failed requests are not stored, so the client may correct and retry them with the same key.
     * An expired response is replaced. The key is inserted rather than updated, so that two
     * requests racing for it collide on the unique index instead of both being kept.
     *
     * @throws UniqueConstraintViolationException when another request stored the key first
     */
    private function remember(User $user, string $key, string $fingerprint, Response $response): void
    {
        $body = $response->getContent();

        if ($body === false || ! $response->isSuccessful()) {
            return;
        }

        $user->idempotencyKeys()->where('key', $key)->where('expires_at', '<=', now())->delete();

        $user->idempotencyKeys()->create([
            'key' => $key,
            'request_fingerprint' => $fingerprint,
            'response_status' => $response->getStatusCode(),
            'response_body' => $body,
            'expires_at' => now()->addHours($this->retentionHours),
        ]);
    }

    /**
     * Rebuild the response that was returned for the original request.
     */
    private function replay(IdempotencyKey $completed): Response
    {
        return response($completed->response_body, $completed->response_status, [
            'Content-Type' => 'application/json',
            self::REPLAYED_HEADER => 'true',
        ]);
    }

    /**
     * Hash the parts of the request that make it the same request.
     */
    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode("\n", [
            $request->method(),
            $request->path(),
            json_encode($this->normalize($request->all()), JSON_THROW_ON_ERROR),
        ]));
    }

    /**
     * Sort object keys recursively so that key order does not change the fingerprint.
     *
     * List order is preserved because it changes the meaning of the request.
     */
    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map($this->normalize(...), $value);

        if (! array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }
}
