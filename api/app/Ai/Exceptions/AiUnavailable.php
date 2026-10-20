<?php

namespace App\Ai\Exceptions;

use App\Ai\AiResponse;
use RuntimeException;

/**
 * The provider is busy or unreachable (429, 5xx, timeout). Retrying later may work.
 * $response is set when a stream broke off part-way, so its tokens are still recorded.
 */
class AiUnavailable extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?AiResponse $response = null,
    ) {
        parent::__construct($message);
    }
}
