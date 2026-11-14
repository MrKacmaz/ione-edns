<?php

namespace App\Services\Observability;

use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\QueryException;
use PDOException;
use RedisException;
use Throwable;

/**
 * Tells an error that only means the database or Redis cannot be reached from any other error.
 *
 * Such an error passes once the service is back, so a queued job waits it out
 * and an API request is answered with 503 and a Retry-After instead of 500.
 */
final class InfrastructureOutageDetector
{
    use DetectsLostConnections;

    /**
     * Determine whether the error only means that the database or Redis cannot be reached.
     *
     * Any Redis error counts, as Redis commands here do not fail for other reasons.
     * A database error counts when it is a lost or refused connection (SQLSTATE class
     * 08), not when the query itself is wrong.
     */
    public function isOutage(Throwable $exception): bool
    {
        if ($exception instanceof RedisException) {
            return true;
        }

        if (! $exception instanceof PDOException) {
            return false;
        }

        $sqlState = $exception instanceof QueryException ? $exception->getPrevious()?->getCode() : $exception->getCode();

        return str_starts_with((string) $sqlState, '08') || $this->causedByLostConnection($exception);
    }
}
