# ez-php/idempotency

`Idempotency-Key` middleware for safe retries of POST/PATCH/PUT/DELETE requests.

The first request with a given key runs normally and its response (status, headers, body) is stored in the cache. A retry with the same key returns the stored response with an `Idempotent-Replayed: true` header instead of executing the handler again.

## Installation

```bash
composer require ez-php/idempotency ez-php/cache
```

`ez-php/cache` is a soft dependency: install it (or provide any `CacheInterface` binding).

## Usage

```php
use EzPhp\Idempotency\IdempotencyMiddleware;

$router->post('/orders', [OrderController::class, 'store'])
    ->middleware(IdempotencyMiddleware::class);
```

Clients send:

```
POST /orders
Idempotency-Key: 8e03978e-40d5-43e8-bc93-6894a57f9324
```

### Behaviour

| Situation | Response |
|---|---|
| No `Idempotency-Key` header, or GET/HEAD/OPTIONS | request passes through |
| Key is not `[A-Za-z0-9_.:-]{1,255}` | `400` |
| Same key, different method / URI / body | `422` |
| Same key while the first request is still running | `409` |
| Same key after completion | stored response + `Idempotent-Replayed: true` |

Responses with status `>= 500` and streamed responses are not stored, so a failed attempt can be retried.

### Configuration

```php
new IdempotencyMiddleware(
    cache: $cache,
    ttl: 86400,     // seconds a response stays replayable
    lockTtl: 30,    // max seconds the in-flight lock is held
    scope: fn (RequestInterface $r): string => (string) $userId, // optional key partition
);
```

Keys are global unless `scope` is set — use it when keys come from authenticated clients so different users cannot collide.

## Development

```bash
composer install
composer full
```

## License

MIT
