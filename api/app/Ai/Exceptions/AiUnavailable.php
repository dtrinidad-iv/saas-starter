<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The provider is busy or unreachable (429, 5xx, timeout). Retrying later may work.
 */
class AiUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }
}
