<?php

declare(strict_types=1);

namespace ShotWolf;

/**
 * Raised when a request exceeds the configured timeout.
 */
class TimeoutException extends ShotWolfException
{
    public function __construct(float $timeout)
    {
        parent::__construct("Request timed out after {$timeout}s", 'timeout', 0);
    }
}
