<?php

declare(strict_types=1);

namespace EzPhp\Idempotency;

use Closure;
use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\MiddlewareInterface;
use EzPhp\Http\RequestInterface;
use EzPhp\Http\Response;
use EzPhp\Http\ResponseInterface;

/**
 * Class IdempotencyMiddleware
 *
 * Makes unsafe requests (POST/PATCH/PUT/DELETE) safe to retry. A request that carries an
 * `Idempotency-Key` header is executed once; its response is stored in the cache and replayed
 * for every later request with the same key and the same fingerprint (method + URI + body hash).
 *
 * Outcomes:
 *  - no header / safe method          → passed through untouched
 *  - malformed key                    → 400
 *  - same key, different request      → 422
 *  - same key, first request in flight → 409
 *  - same key, completed              → stored response, plus `Idempotent-Replayed: true`
 *
 * Keys are partitioned per caller: the partition is `$scope` when given, otherwise a hash of the
 * `Authorization` and `Cookie` headers, so two callers that pick the same key never share an entry.
 *
 * Only {@see Response} results below 500 are stored, so a failed attempt can be retried.
 * Streamed responses and cookies are never stored.
 *
 * @package EzPhp\Idempotency
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAY_HEADER = 'Idempotent-Replayed';

    private const METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    private const KEY_PATTERN = '/\A[A-Za-z0-9_.:-]{1,255}\z/';

    /**
     * @param CacheInterface                        $cache   Stores responses and the in-flight lock.
     * @param int                                   $ttl     Seconds a stored response stays replayable.
     * @param int                                   $lockTtl Seconds the in-flight lock is held at most.
     * @param Closure(RequestInterface): string|null $scope  Optional partition (e.g. user id) replacing the
     *                                                       default `Authorization` + `Cookie` header hash.
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttl = 86400,
        private readonly int $lockTtl = 30,
        private readonly ?Closure $scope = null,
    ) {
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     *
     * @return ResponseInterface
     */
    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {
        $key = $request->header(self::HEADER);

        if (!in_array(strtoupper($request->method()), self::METHODS, true) || $key === null) {
            /** @var ResponseInterface */
            return $next($request);
        }

        if (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return self::error(400, 'Invalid Idempotency-Key header.');
        }

        $storageKey = 'idempotency:' . hash('sha256', $this->scopeFor($request)) . ':' . $key;
        $fingerprint = hash('sha256', strtoupper($request->method()) . "\n" . $request->uri() . "\n" . hash('sha256', $request->rawBody()));

        $early = $this->replay($storageKey, $fingerprint);

        if ($early !== null) {
            return $early;
        }

        $lock = $this->cache->lock($storageKey . ':lock', $this->lockTtl);

        if (!$lock->acquire()) {
            return self::error(409, 'A request with this Idempotency-Key is already in progress.');
        }

        try {
            // A concurrent request may have completed between the first lookup and the lock.
            $early = $this->replay($storageKey, $fingerprint);

            if ($early !== null) {
                return $early;
            }

            /** @var ResponseInterface $response */
            $response = $next($request);

            if ($response instanceof Response && $response->status() < 500) {
                $this->cache->set($storageKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->status(),
                    'headers' => $response->headers(),
                    'body' => $response->body(),
                ], $this->ttl);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * The caller partition: the custom scope, or the credentials the request presents.
     *
     * @param RequestInterface $request
     *
     * @return string
     */
    private function scopeFor(RequestInterface $request): string
    {
        if ($this->scope !== null) {
            return ($this->scope)($request);
        }

        $authorization = $request->header('authorization');
        $cookie = $request->header('cookie');

        return (is_string($authorization) ? $authorization : '') . "\n" . (is_string($cookie) ? $cookie : '');
    }

    /**
     * Return the stored response (or a 422) when the key was already used, null when it is new.
     *
     * @param string $storageKey
     * @param string $fingerprint
     *
     * @return Response|null
     */
    private function replay(string $storageKey, string $fingerprint): ?Response
    {
        $stored = $this->cache->get($storageKey);

        if (
            !is_array($stored)
            || !is_string($stored['fingerprint'] ?? null)
            || !is_int($stored['status'] ?? null)
            || !is_string($stored['body'] ?? null)
            || !is_array($stored['headers'] ?? null)
        ) {
            return null;
        }

        if (!hash_equals($stored['fingerprint'], $fingerprint)) {
            return self::error(422, 'Idempotency-Key was already used with a different request.');
        }

        $response = new Response($stored['body'], $stored['status']);

        foreach ($stored['headers'] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response->withHeader(self::REPLAY_HEADER, 'true');
    }

    /**
     * @param int    $status
     * @param string $message
     *
     * @return Response
     */
    private static function error(int $status, string $message): Response
    {
        return (new Response(json_encode(['error' => $message], JSON_THROW_ON_ERROR), $status))
            ->withHeader('Content-Type', 'application/json');
    }
}
