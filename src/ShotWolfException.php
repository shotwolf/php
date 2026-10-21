<?php

declare(strict_types=1);

namespace ShotWolf;

/**
 * Raised for any non-2xx API response. `$type` mirrors the API error envelope
 * (invalid_params, insufficient_credits, rate_limited, ...). `$status` is the
 * HTTP status code (0 for client-side / transport errors).
 */
class ShotWolfException extends \RuntimeException
{
    public string $type;
    public int $status;
    public ?string $requestId;
    public ?float $retryAfter;

    public function __construct(
        string $message,
        string $type = 'unknown',
        int $status = 0,
        ?string $requestId = null,
        ?float $retryAfter = null
    ) {
        parent::__construct($message);
        $this->type = $type;
        $this->status = $status;
        $this->requestId = $requestId;
        $this->retryAfter = $retryAfter;
    }
}
