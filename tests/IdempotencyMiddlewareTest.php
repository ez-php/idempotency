<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\ArrayLock;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\Http\StreamedResponse;
use EzPhp\Idempotency\IdempotencyMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(IdempotencyMiddleware::class)]
final class IdempotencyMiddlewareTest extends TestCase
{
    private ArrayDriver $cache;

    private int $calls = 0;

    protected function setUp(): void
    {
        ArrayLock::reset();
        $this->cache = new ArrayDriver();
        $this->calls = 0;
    }

    private function request(?string $key, string $body = '{"a":1}', string $method = 'POST', string $uri = '/orders'): Request
    {
        return new Request($method, $uri, headers: $key === null ? [] : ['idempotency-key' => $key], rawBody: $body);
    }

    private function dispatch(IdempotencyMiddleware $middleware, Request $request, int $status = 201): Response
    {
        $response = $middleware->handle($request, function () use ($status): Response {
            $this->calls++;

            return (new Response('created-' . $this->calls, $status))->withHeader('X-Test', 'yes');
        });
        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    public function test_request_without_header_is_passed_through(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $this->dispatch($mw, $this->request(null));
        $this->dispatch($mw, $this->request(null));

        self::assertSame(2, $this->calls);
    }

    public function test_safe_method_is_passed_through(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $this->dispatch($mw, $this->request('k1', method: 'GET'));
        $this->dispatch($mw, $this->request('k1', method: 'GET'));

        self::assertSame(2, $this->calls);
    }

    public function test_repeat_replays_stored_response(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $first = $this->dispatch($mw, $this->request('k1'));
        $second = $this->dispatch($mw, $this->request('k1'));

        self::assertSame(1, $this->calls);
        self::assertSame(201, $second->status());
        self::assertSame($first->body(), $second->body());
        self::assertSame('yes', $second->headers()['X-Test']);
        self::assertSame('true', $second->headers()[IdempotencyMiddleware::REPLAY_HEADER]);
        self::assertArrayNotHasKey(IdempotencyMiddleware::REPLAY_HEADER, $first->headers());
    }

    public function test_different_body_with_same_key_is_rejected(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $this->dispatch($mw, $this->request('k1'));
        $second = $this->dispatch($mw, $this->request('k1', '{"a":2}'));

        self::assertSame(422, $second->status());
        self::assertSame(1, $this->calls);
    }

    public function test_different_uri_with_same_key_is_rejected(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $this->dispatch($mw, $this->request('k1'));
        $second = $this->dispatch($mw, $this->request('k1', uri: '/other'));

        self::assertSame(422, $second->status());
    }

    public function test_concurrent_request_gets_409_while_lock_is_held(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);
        $lock = $this->cache->lock('idempotency:k1:lock', 30);
        self::assertTrue($lock->acquire());

        $response = $this->dispatch($mw, $this->request('k1'));

        self::assertSame(409, $response->status());
        self::assertSame(0, $this->calls);
    }

    public function test_lock_is_released_after_handling(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $this->dispatch($mw, $this->request('k1'));

        self::assertTrue($this->cache->lock('idempotency:k1:lock', 30)->acquire());
    }

    public function test_lock_is_released_when_handler_throws(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        try {
            $mw->handle($this->request('k1'), static function (): never {
                throw new \RuntimeException('boom');
            });
            self::fail('Expected exception');
        } catch (\RuntimeException) {
        }

        self::assertTrue($this->cache->lock('idempotency:k1:lock', 30)->acquire());
    }

    public function test_server_errors_are_not_stored(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $this->dispatch($mw, $this->request('k1'), 503);
        $second = $this->dispatch($mw, $this->request('k1'), 201);

        self::assertSame(2, $this->calls);
        self::assertSame(201, $second->status());
    }

    public function test_client_errors_are_stored(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $this->dispatch($mw, $this->request('k1'), 400);
        $second = $this->dispatch($mw, $this->request('k1'), 201);

        self::assertSame(1, $this->calls);
        self::assertSame(400, $second->status());
    }

    public function test_streamed_responses_are_not_stored(): void
    {
        $mw = new IdempotencyMiddleware($this->cache);
        $handler = function (): StreamedResponse {
            $this->calls++;

            return StreamedResponse::sse(static function (): \Generator {
                yield from [];
            });
        };

        $mw->handle($this->request('k1'), $handler);
        $mw->handle($this->request('k1'), $handler);

        self::assertSame(2, $this->calls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'space' => ['bad key'],
            'slash' => ['a/b'],
            'too long' => [str_repeat('a', 256)],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function test_malformed_key_is_rejected(string $key): void
    {
        $mw = new IdempotencyMiddleware($this->cache);

        $response = $this->dispatch($mw, $this->request($key));

        self::assertSame(400, $response->status());
        self::assertSame(0, $this->calls);
    }

    public function test_stored_response_expires_after_ttl(): void
    {
        $mw = new IdempotencyMiddleware($this->cache, ttl: 1);

        $this->dispatch($mw, $this->request('k1'));
        sleep(2);
        $this->dispatch($mw, $this->request('k1'));

        self::assertSame(2, $this->calls);
    }

    public function test_scope_partitions_keys(): void
    {
        $user = 'alice';
        $mw = new IdempotencyMiddleware($this->cache, scope: static function () use (&$user): string {
            return $user;
        });

        $this->dispatch($mw, $this->request('k1'));
        $user = 'bob';
        $this->dispatch($mw, $this->request('k1'));

        self::assertSame(2, $this->calls);
    }
}
