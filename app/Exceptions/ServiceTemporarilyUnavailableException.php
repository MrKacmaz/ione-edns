<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Answers a request with 503 while the database or Redis cannot be reached.
 *
 * The same request may succeed a few seconds later, which a 500 would not tell
 * the client; Retry-After says when to try again.
 */
class ServiceTemporarilyUnavailableException extends ServiceUnavailableHttpException
{
    /**
     * The seconds a client is asked to wait before it sends the request again.
     */
    public const int RETRY_AFTER_SECONDS = 10;

    /**
     * Create the answer to a request that ran into an outage.
     */
    public static function causedBy(Throwable $outage): self
    {
        return new self(self::RETRY_AFTER_SECONDS, __('errors.service_unavailable'), $outage);
    }
}
