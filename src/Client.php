<?php

declare(strict_types=1);

namespace ShotWolf;

/**
 * Client for the ShotWolf API. Pass an API key (`sw_live_...`) or set the
 * `SHOTWOLF_API_KEY` environment variable.
 *
 *   $sw = new ShotWolf\Client();
 *   $shot = $sw->capture(['url' => 'https://example.com', 'device' => 'iphone_15_pro']);
 *   echo $shot['image_url'];
 *
 * @phpstan-type Transport callable(string,string,array<string,string>,?string,float):array{0:int,1:array<string,string>,2:?string}
 */
class Client
{
    public const VERSION = '0.1.0';

    private const DEFAULT_BASE_URL = 'https://shotwolf.com';
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    private string $apiKey;
    private string $baseUrl;
    private float $timeout;
    private int $maxRetries;
    private float $retryBackoff;

    /** @var callable */
    private $transport;

    /**
     * @param array{
     *   base_url?: string,
     *   timeout?: float|int,
     *   max_retries?: int,
     *   retry_backoff?: float|int,
     *   transport?: callable
     * } $options
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $apiKey = $apiKey ?? (getenv('SHOTWOLF_API_KEY') ?: null);
        if ($apiKey === null || $apiKey === '') {
            throw new ShotWolfException(
                'Missing API key. Pass $apiKey or set SHOTWOLF_API_KEY.',
                'unauthorized'
            );
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($options['timeout'] ?? 60);
        $this->maxRetries = (int) ($options['max_retries'] ?? 2);
        $this->retryBackoff = (float) ($options['retry_backoff'] ?? 0.5);
        $this->transport = $options['transport'] ?? [$this, 'curlTransport'];
    }

    /**
     * Capture a URL or HTML synchronously. POST /api/v1/captures
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function capture(array $params, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->request('POST', '/api/v1/captures', $params, $idempotencyKey, $timeout);
    }

    /**
     * Queue a capture and return immediately. POST /api/v1/captures/async
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function captureAsync(array $params, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->request('POST', '/api/v1/captures/async', $params, $idempotencyKey, $timeout);
    }

    /**
     * Render one URL across 2-6 devices. POST /api/v1/capture/batch
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function batch(array $params, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->request('POST', '/api/v1/capture/batch', $params, $idempotencyKey, $timeout);
    }

    /**
     * Render a URL inside a device / social mockup. POST /api/v1/mockups
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function mockup(array $params, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->request('POST', '/api/v1/mockups', $params, $idempotencyKey, $timeout);
    }

    /**
     * Generate an Open Graph card from a template. POST /api/v1/og
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function og(array $params, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->request('POST', '/api/v1/og', $params, $idempotencyKey, $timeout);
    }

    /**
     * Convert a URL or HTML string to PDF. POST /api/v1/pdf
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function pdf(array $params, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->request('POST', '/api/v1/pdf', $params, $idempotencyKey, $timeout);
    }

    /**
     * Pixel-diff two screenshots. POST /api/v1/diffs
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function diff(array $params, ?string $idempotencyKey = null, ?float $timeout = null): array
    {
        return $this->request('POST', '/api/v1/diffs', $params, $idempotencyKey, $timeout);
    }

    /**
     * Retrieve a single screenshot by ID. GET /api/v1/screenshots/:id
     *
     * @return array<string,mixed>
     */
    public function getScreenshot(int $id, ?float $timeout = null): array
    {
        return $this->request('GET', '/api/v1/screenshots/' . $id, null, null, $timeout);
    }

    /**
     * Get balance, tier and webhook secret. GET /api/v1/account
     *
     * @return array<string,mixed>
     */
    public function account(?float $timeout = null): array
    {
        return $this->request('GET', '/api/v1/account', null, null, $timeout);
    }

    /**
     * Poll getScreenshot() until the shot is `done` or `failed`. Returns the
     * final screenshot array; throws ShotWolfException on render failure or
     * TimeoutException if the deadline passes.
     *
     * @return array<string,mixed>
     */
    public function waitFor(int $id, float $interval = 2.0, float $timeout = 120.0): array
    {
        $deadline = $this->monotonic() + $timeout;
        while (true) {
            $shot = $this->getScreenshot($id);
            if (($shot['status'] ?? null) === 'done') {
                return $shot;
            }
            if (($shot['status'] ?? null) === 'failed') {
                throw new ShotWolfException("Screenshot {$id} failed to render", 'render_failed', 422);
            }
            if ($this->monotonic() + $interval > $deadline) {
                throw new TimeoutException($timeout);
            }
            $this->sleep($interval);
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $idempotencyKey = null,
        ?float $timeout = null
    ): array {
        $url = $this->baseUrl . $path;
        $reqTimeout = $timeout ?? $this->timeout;
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'shotwolf-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                [$status, $respHeaders, $respBody] =
                    ($this->transport)($method, $url, $headers, $payload, $reqTimeout);
            } catch (\Throwable $e) {
                $lastError = $e;
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->backoff($attempt));
                    continue;
                }
                throw $e;
            }

            if ($status >= 200 && $status < 300) {
                if ($respBody === null || $respBody === '') {
                    return [];
                }
                /** @var array<string,mixed> $decoded */
                $decoded = json_decode($respBody, true, 512, JSON_THROW_ON_ERROR);
                return $decoded;
            }

            if (in_array($status, self::RETRYABLE_STATUSES, true) && $attempt < $this->maxRetries) {
                $this->sleep($this->parseRetryAfter($respHeaders) ?? $this->backoff($attempt));
                continue;
            }

            throw $this->toError($status, $respHeaders, $respBody);
        }

        throw $lastError instanceof \Throwable ? $lastError : new ShotWolfException('Request failed');
    }

    /**
     * Default transport: a single cURL request. Throws TimeoutException on
     * timeout, ShotWolfException on any other transport-level failure.
     *
     * @param array<string,string> $headers
     * @return array{0:int,1:array<string,string>,2:?string}
     */
    private function curlTransport(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ShotWolfException('Failed to init cURL', 'transport_error');
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($timeout * 1000),
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $respBody = curl_exec($ch);
        if ($respBody === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new TimeoutException($timeout);
            }
            throw new ShotWolfException("Transport error: {$err}", 'transport_error');
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $respHeaders, is_string($respBody) ? $respBody : null];
    }

    private function monotonic(): float
    {
        return hrtime(true) / 1e9;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) ($seconds * 1_000_000));
        }
    }

    private function backoff(int $attempt): float
    {
        return $this->retryBackoff * (2 ** $attempt);
    }

    /**
     * @param array<string,string> $headers
     */
    private function parseRetryAfter(array $headers): ?float
    {
        $raw = $headers['retry-after'] ?? null;
        if ($raw === null || !is_numeric($raw)) {
            return null;
        }
        return (float) $raw;
    }

    /**
     * @param array<string,string> $headers
     */
    private function toError(int $status, array $headers, ?string $body): ShotWolfException
    {
        $type = 'unknown';
        $message = "HTTP {$status}";

        $data = json_decode((string) $body, true);
        if (is_array($data) && isset($data['error']) && is_array($data['error'])) {
            $type = is_string($data['error']['type'] ?? null) ? $data['error']['type'] : 'unknown';
            $message = is_string($data['error']['message'] ?? null) ? $data['error']['message'] : $message;
        }

        return new ShotWolfException(
            $message,
            $type,
            $status,
            $headers['x-request-id'] ?? null,
            $this->parseRetryAfter($headers)
        );
    }
}
