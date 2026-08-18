<?php

namespace App\Http\Errors;

use RuntimeException;
use Throwable;

/**
 * Throw this from application/domain code to produce the standard
 * {"error": {"code", "message", "details"}} envelope (§52).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
