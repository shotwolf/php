# ShotWolf PHP SDK

Official PHP client for the [ShotWolf](https://shotwolf.com) API - capture screenshots, render device mockups, generate Open Graph cards, convert HTML to PDF, and run pixel-level visual regression diffs through a single JSON HTTP API.

- Zero runtime dependencies (ext-curl + ext-json only, PHP 8.1+)
- Built-in retries with backoff, idempotency keys, and an async polling helper

## Install

```bash
composer require shotwolf/shotwolf
```

## Quick start

Get an API key at [shotwolf.com/dashboard/api_keys](https://shotwolf.com/dashboard/api_keys).

```php
require 'vendor/autoload.php';

use ShotWolf\Client;

$sw = new Client(); // reads SHOTWOLF_API_KEY, or pass new Client('sw_live_...')

$shot = $sw->capture(['url' => 'https://example.com', 'full_page' => true, 'dismiss_cookies' => true]);
echo $shot['image_url'];
```

## Usage

### Screenshots

```php
// Sync - returns the image URL inline (~1-4s)
$shot = $sw->capture(['url' => 'https://stripe.com', 'device' => 'iphone_15_pro']);

// Async - queue and poll (or pass 'webhook_url' for a callback)
$queued = $sw->captureAsync(['url' => 'https://stripe.com']);
$done = $sw->waitFor($queued['id']); // polls until status is done

// Batch - one page, many viewports (cheaper than N calls)
$batch = $sw->batch(['url' => 'https://stripe.com', 'devices' => ['default', 'iphone_15_pro', 'ipad_pro_12_9']]);
```

### Device & social mockups

```php
$mockup = $sw->mockup(['url' => 'https://stripe.com', 'frame' => 'macbook_air', 'bg' => 'gradient']);
```

### Open Graph cards

```php
$og = $sw->og(['template' => 'blog_card', 'variables' => ['title' => 'Ship faster', 'author' => 'Jane Doe']]);
```

### HTML / URL to PDF

```php
$pdf = $sw->pdf(['html' => '<h1>Invoice #42</h1>', 'page_format' => 'A4', 'print_background' => true]);
```

### Visual regression diff

```php
$diff = $sw->diff([
    'before_url' => 'https://staging.example.com',
    'after_url' => 'https://example.com',
    'ignore_regions' => [['x' => 0, 'y' => 0, 'width' => 1280, 'height' => 80]], // mask a dynamic header
]);
if (!$diff['passed']) {
    printf("%.2f%% changed\n", $diff['diff_ratio'] * 100);
}
```

### Account balance

```php
$account = $sw->account();
echo $account['balance_cr'], ' ', $account['tier']['name'];
```

## Idempotency

Pass an idempotency key (2nd argument) to make money-charging POSTs safe to retry.
A repeat with the same key and body within 24h replays the original response
instead of charging again.

```php
$sw->capture(['url' => 'https://example.com'], bin2hex(random_bytes(16)));
```

## Error handling

Any non-2xx response throws `ShotWolf\ShotWolfException` carrying the API error
`type`, HTTP `status`, and `message`.

```php
use ShotWolf\ShotWolfException;

try {
    $sw->capture(['url' => 'https://example.com']);
} catch (ShotWolfException $e) {
    // $e->type === 'insufficient_credits' -> top up at https://shotwolf.com/pricing
    error_log(implode(' ', [$e->status, $e->type, $e->getMessage(), (string) $e->requestId]));
}
```

## Configuration

```php
$sw = new Client('sw_live_xxx', [
    'base_url' => 'https://shotwolf.com', // override for self-host / testing
    'timeout' => 60,                      // per-request timeout in seconds
    'max_retries' => 2,                   // retries on 429 / 5xx
    'retry_backoff' => 0.5,               // base backoff, doubled per attempt
]);
```

Retries honor the `Retry-After` header on `429` responses. `4xx` responses
other than `429` are never retried.

## API coverage

| Method | Endpoint |
| --- | --- |
| `capture(array $params)` | `POST /api/v1/captures` |
| `captureAsync(array $params)` | `POST /api/v1/captures/async` |
| `batch(array $params)` | `POST /api/v1/capture/batch` |
| `mockup(array $params)` | `POST /api/v1/mockups` |
| `og(array $params)` | `POST /api/v1/og` |
| `pdf(array $params)` | `POST /api/v1/pdf` |
| `diff(array $params)` | `POST /api/v1/diffs` |
| `getScreenshot(int $id)` | `GET /api/v1/screenshots/:id` |
| `waitFor(int $id)` | polls `getScreenshot` until `done`/`failed` |
| `account()` | `GET /api/v1/account` |

Full parameter reference: [shotwolf.com/docs](https://shotwolf.com/docs).

## License

MIT
