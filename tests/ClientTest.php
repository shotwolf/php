<?php

declare(strict_types=1);

namespace ShotWolf\Tests;

use PHPUnit\Framework\TestCase;
use ShotWolf\Client;
use ShotWolf\ShotWolfException;
use ShotWolf\TimeoutException;

final class ClientTest extends TestCase
{
    /**
     * Build a fake transport returning queued responses, recording calls.
     * Each response: ['status' => int, 'body' => array|null, 'headers' => array, 'raise' => \Throwable].
     *
     * @param list<array<string,mixed>> $responses
     * @param array<int,array<string,mixed>> $calls captured by reference
     * @return callable
     */
    private function fakeTransport(array $responses, array &$calls): callable
    {
        $i = 0;
        return function (string $method, string $url, array $headers, ?string $body, float $timeout)
            use ($responses, &$calls, &$i): array {
            $calls[] = compact('method', 'url', 'headers', 'body', 'timeout');
            $spec = $responses[min($i, count($responses) - 1)];
            $i++;
            if (isset($spec['raise'])) {
                throw $spec['raise'];
            }
            $payload = $spec['body'] ?? null;
            $raw = $payload === null ? '' : json_encode($payload);
            return [$spec['status'], $spec['headers'] ?? [], $raw];
        };
    }

    /**
     * @param list<array<string,mixed>> $responses
     * @param array<int,array<string,mixed>> $calls
     * @param array<string,mixed> $opts
     */
    private function client(array $responses, array &$calls, array $opts = []): Client
    {
        $opts['transport'] = $this->fakeTransport($responses, $calls);
        $opts['retry_backoff'] = $opts['retry_backoff'] ?? 0;
        return new Client('sw_live_test', $opts);
    }

    public function testMissingApiKeyThrows(): void
    {
        putenv('SHOTWOLF_API_KEY');
        $this->expectException(ShotWolfException::class);
        new Client(null, ['transport' => fn () => [200, [], '{}']]);
    }

    public function testReadsEnvKey(): void
    {
        putenv('SHOTWOLF_API_KEY=sw_live_env');
        try {
            $calls = [];
            $transport = $this->fakeTransport([['status' => 200, 'body' => ['balance_cr' => 5]]], $calls);
            (new Client(null, ['transport' => $transport]))->account();
            $this->assertSame('Bearer sw_live_env', $calls[0]['headers']['Authorization']);
        } finally {
            putenv('SHOTWOLF_API_KEY');
        }
    }

    public function testCapturePostsJsonAndReturnsBody(): void
    {
        $calls = [];
        $sw = $this->client(
            [['status' => 201, 'body' => ['id' => 42, 'status' => 'done', 'image_url' => 'https://cdn/x.png']]],
            $calls
        );
        $res = $sw->capture(['url' => 'https://example.com', 'full_page' => true]);

        $this->assertSame('POST', $calls[0]['method']);
        $this->assertSame('https://shotwolf.com/api/v1/captures', $calls[0]['url']);
        $this->assertSame('Bearer sw_live_test', $calls[0]['headers']['Authorization']);
        $this->assertSame('application/json', $calls[0]['headers']['Content-Type']);
        $this->assertSame(
            ['url' => 'https://example.com', 'full_page' => true],
            json_decode($calls[0]['body'], true)
        );
        $this->assertSame(['id' => 42, 'status' => 'done', 'image_url' => 'https://cdn/x.png'], $res);
    }

    public function testCustomBaseUrl(): void
    {
        $calls = [];
        $sw = $this->client(
            [['status' => 201, 'body' => ['id' => 1]]],
            $calls,
            ['base_url' => 'http://localhost:6300/']
        );
        $sw->capture(['url' => 'https://e.com']);
        $this->assertSame('http://localhost:6300/api/v1/captures', $calls[0]['url']);
    }

    public function testIdempotencyKeyHeader(): void
    {
        $calls = [];
        $sw = $this->client([['status' => 201, 'body' => ['id' => 1]]], $calls);
        $sw->capture(['url' => 'https://e.com'], 'abc-123');
        $this->assertSame('abc-123', $calls[0]['headers']['Idempotency-Key']);
    }

    public function testErrorEnvelopeThrows(): void
    {
        $calls = [];
        $sw = $this->client(
            [['status' => 402, 'body' => ['error' => ['type' => 'insufficient_credits', 'message' => 'Broke']]]],
            $calls
        );
        try {
            $sw->capture(['url' => 'https://e.com']);
            $this->fail('expected ShotWolfException');
        } catch (ShotWolfException $e) {
            $this->assertSame('insufficient_credits', $e->type);
            $this->assertSame(402, $e->status);
            $this->assertSame('Broke', $e->getMessage());
        }
    }

    public function testErrorCarriesRequestId(): void
    {
        $calls = [];
        $sw = $this->client(
            [[
                'status' => 400,
                'body' => ['error' => ['type' => 'invalid_params', 'message' => 'bad']],
                'headers' => ['x-request-id' => 'req_9'],
            ]],
            $calls
        );
        try {
            $sw->capture(['url' => 'x']);
            $this->fail('expected ShotWolfException');
        } catch (ShotWolfException $e) {
            $this->assertSame('req_9', $e->requestId);
        }
    }

    public function testRetriesOn429ThenSucceeds(): void
    {
        $calls = [];
        $sw = $this->client(
            [
                ['status' => 429, 'body' => ['error' => ['type' => 'rate_limited', 'message' => 'slow']]],
                ['status' => 201, 'body' => ['id' => 7, 'status' => 'done']],
            ],
            $calls
        );
        $res = $sw->capture(['url' => 'https://e.com']);
        $this->assertCount(2, $calls);
        $this->assertSame(7, $res['id']);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $calls = [];
        $sw = $this->client(
            [['status' => 503, 'body' => ['error' => ['type' => 'render_failed', 'message' => 'down']]]],
            $calls,
            ['max_retries' => 1]
        );
        try {
            $sw->capture(['url' => 'https://e.com']);
            $this->fail('expected ShotWolfException');
        } catch (ShotWolfException $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertCount(2, $calls);
    }

    public function testNoRetryOn400(): void
    {
        $calls = [];
        $sw = $this->client(
            [['status' => 400, 'body' => ['error' => ['type' => 'invalid_params', 'message' => 'no']]]],
            $calls
        );
        $this->expectException(ShotWolfException::class);
        try {
            $sw->capture(['url' => 'x']);
        } finally {
            $this->assertCount(1, $calls);
        }
    }

    public function testRetriesOnTransportError(): void
    {
        $calls = [];
        $sw = $this->client(
            [
                ['status' => 0, 'raise' => new \RuntimeException('boom')],
                ['status' => 201, 'body' => ['id' => 3]],
            ],
            $calls
        );
        $res = $sw->capture(['url' => 'https://e.com']);
        $this->assertSame(3, $res['id']);
        $this->assertCount(2, $calls);
    }

    public function testGetScreenshotIsGetNoBody(): void
    {
        $calls = [];
        $sw = $this->client([['status' => 200, 'body' => ['id' => 5, 'status' => 'queued']]], $calls);
        $sw->getScreenshot(5);
        $this->assertSame('https://shotwolf.com/api/v1/screenshots/5', $calls[0]['url']);
        $this->assertSame('GET', $calls[0]['method']);
        $this->assertNull($calls[0]['body']);
    }

    public function testWaitForPollsUntilDone(): void
    {
        $calls = [];
        $sw = $this->client(
            [
                ['status' => 200, 'body' => ['id' => 5, 'status' => 'processing']],
                ['status' => 200, 'body' => ['id' => 5, 'status' => 'processing']],
                ['status' => 200, 'body' => ['id' => 5, 'status' => 'done', 'image_url' => 'https://cdn/y.png']],
            ],
            $calls
        );
        $res = $sw->waitFor(5, 0.001, 5);
        $this->assertSame('done', $res['status']);
        $this->assertCount(3, $calls);
    }

    public function testWaitForThrowsOnFailed(): void
    {
        $calls = [];
        $sw = $this->client([['status' => 200, 'body' => ['id' => 5, 'status' => 'failed']]], $calls);
        try {
            $sw->waitFor(5, 0.001, 5);
            $this->fail('expected ShotWolfException');
        } catch (ShotWolfException $e) {
            $this->assertSame('render_failed', $e->type);
        }
    }

    public function testWaitForTimesOut(): void
    {
        $calls = [];
        $sw = $this->client([['status' => 200, 'body' => ['id' => 5, 'status' => 'processing']]], $calls);
        $this->expectException(TimeoutException::class);
        $sw->waitFor(5, 0.01, 0.001);
    }

    public function testEndpointPaths(): void
    {
        $cases = [
            'mockup' => [['url' => 'https://e.com', 'frame' => 'iphone_15_pro'], 'https://shotwolf.com/api/v1/mockups'],
            'og' => [['template' => 'blog_card'], 'https://shotwolf.com/api/v1/og'],
            'pdf' => [['url' => 'https://e.com'], 'https://shotwolf.com/api/v1/pdf'],
            'diff' => [
                ['before_url' => 'https://a.com', 'after_url' => 'https://b.com'],
                'https://shotwolf.com/api/v1/diffs',
            ],
            'batch' => [
                ['url' => 'https://e.com', 'devices' => ['default', 'iphone_15_pro']],
                'https://shotwolf.com/api/v1/capture/batch',
            ],
            'captureAsync' => [['url' => 'https://e.com'], 'https://shotwolf.com/api/v1/captures/async'],
        ];
        foreach ($cases as $method => [$params, $path]) {
            $calls = [];
            $sw = $this->client([['status' => 201, 'body' => ['id' => 1]]], $calls);
            $sw->{$method}($params);
            $this->assertSame($path, $calls[0]['url'], "{$method} path");
        }
    }

    public function testAccountGet(): void
    {
        $calls = [];
        $sw = $this->client(
            [['status' => 200, 'body' => ['email_address' => 'a@b.co', 'balance_cr' => 5]]],
            $calls
        );
        $acc = $sw->account();
        $this->assertSame('https://shotwolf.com/api/v1/account', $calls[0]['url']);
        $this->assertSame(5, $acc['balance_cr']);
    }
}
