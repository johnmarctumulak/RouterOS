<?php

declare(strict_types=1);

namespace RouterOS;

use RouterOS\Exception\ConnectionException;

/**
 * Thread-safe (single-process) connection pool for managing multiple
 * router connections or reusing connections to the same router.
 *
 * Useful when querying many routers in a loop (MSP / RMM scenarios).
 *
 * Also enforces an optional minimum interval between consecutive requests
 * to the same router — preventing accidental CPU spikes on embedded hardware.
 *
 * Usage:
 *   $pool = new ConnectionPool(maxSize: 10, idleTimeout: 60, minRequestIntervalMs: 100);
 *
 *   $client = $pool->acquire(new Config('192.168.1.1', 'api', 'secret'));
 *   $rows   = $client->command('/interface/print');
 *   $pool->release($client, $config);
 *
 *   // Or use the helper:
 *   $pool->use(new Config('192.168.1.1', 'api', 'secret'), function(Client $c) {
 *       return $c->command('/ip/address/print');
 *   });
 */
final class ConnectionPool
{
    private int $maxSize;
    private int $idleTimeout;

    /**
     * Minimum milliseconds between consecutive requests to the same router.
     * 0 = no limit. Prevents hammering embedded MikroTik CPUs.
     */
    private int $minRequestIntervalMs;

    /**
     * Tracks the last request time (as microtime float) per pool key.
     * @var array<string, float>
     */
    private array $lastRequestTime = [];

    /**
     * key → pool key (host:port:user)
     * value → array of ['client' => Client, 'idle_since' => float]
     *
     * @var array<string, list<array{client: Client, idle_since: float}>>
     */
    private array $idle = [];

    /** Count of currently checked-out connections per pool key */
    /** @var array<string, int> */
    private array $active = [];

    /**
     * @param int $maxSize             Maximum connections to keep idle per pool key
     * @param int $idleTimeout         Seconds before an idle connection is closed
     * @param int $minRequestIntervalMs Minimum ms between requests to the same router (0 = unlimited)
     *                                  Recommended: 50–200 ms for embedded RouterBoard hardware
     */
    public function __construct(int $maxSize = 5, int $idleTimeout = 60, int $minRequestIntervalMs = 0)
    {
        $this->maxSize              = $maxSize;
        $this->idleTimeout          = $idleTimeout;
        $this->minRequestIntervalMs = $minRequestIntervalMs;
    }

    // --- Public API -----------------------------------------------------------

    /**
     * Acquires an active Client for the given config, reusing an idle one if available.
     * Enforces minRequestIntervalMs to prevent hammering the router.
     *
     * @throws ConnectionException
     * @throws \RouterOS\Exception\AuthenticationException
     */
    public function acquire(Config $config): Client
    {
        $key = $this->key($config);
        $this->throttle($key);   // ← rate-limiter: sleep if needed
        $this->evictStale($key);

        // Reuse idle connection if available
        if (!empty($this->idle[$key])) {
            $entry  = array_pop($this->idle[$key]);
            $client = $entry['client'];

            if ($client->isConnected()) {
                $this->active[$key] = ($this->active[$key] ?? 0) + 1;
                return $client;
            }
        }

        $client = Client::connect($config);
        $this->active[$key] = ($this->active[$key] ?? 0) + 1;
        return $client;
    }

    /**
     * Returns a Client to the pool. If the pool for this key is full,
     * the connection is closed instead.
     */
    public function release(Client $client, Config $config): void
    {
        $key = $this->key($config);
        $this->active[$key] = max(0, ($this->active[$key] ?? 1) - 1);

        $idleCount = count($this->idle[$key] ?? []);

        if ($idleCount < $this->maxSize && $client->isConnected()) {
            $this->idle[$key][] = ['client' => $client, 'idle_since' => microtime(true)];
        } else {
            $client->close();
        }
    }

    /**
     * Borrows a connection, runs $callback, and releases automatically.
     *
     * @template T
     * @param callable(Client): T $callback
     * @return T
     * @throws ConnectionException
     */
    public function use(Config $config, callable $callback): mixed
    {
        $client = $this->acquire($config);

        try {
            $result = $callback($client);
            $this->release($client, $config);
            return $result;
        } catch (\Throwable $e) {
            // Do not return broken connections to the pool
            $client->close();
            $key = $this->key($config);
            $this->active[$key] = max(0, ($this->active[$key] ?? 1) - 1);
            throw $e;
        }
    }

    /**
     * Closes all idle connections in the pool.
     */
    public function closeAll(): void
    {
        foreach ($this->idle as $entries) {
            foreach ($entries as $entry) {
                $entry['client']->close();
            }
        }
        $this->idle   = [];
        $this->active = [];
    }

    // --- Internal management --------------------------------------------------

    private function key(Config $config): string
    {
        return "{$config->host}:{$config->port}:{$config->username}";
    }

    /**
     * Sleeps the remaining microseconds needed to enforce minRequestIntervalMs.
     * Called before every acquire() to protect the router from rapid-fire requests.
     */
    private function throttle(string $key): void
    {
        if ($this->minRequestIntervalMs <= 0) {
            return;
        }

        $now      = microtime(true);
        $last     = $this->lastRequestTime[$key] ?? 0.0;
        $elapsed  = ($now - $last) * 1000; // ms
        $remaining = $this->minRequestIntervalMs - $elapsed;

        if ($remaining > 0) {
            usleep((int) ($remaining * 1000)); // usleep takes microseconds
        }

        $this->lastRequestTime[$key] = microtime(true);
    }

    private function evictStale(string $key): void
    {
        if (!isset($this->idle[$key])) {
            return;
        }

        $now = microtime(true);

        $this->idle[$key] = array_values(array_filter(
            $this->idle[$key],
            function (array $entry) use ($now): bool {
                if (($now - $entry['idle_since']) > $this->idleTimeout) {
                    $entry['client']->close();
                    return false;
                }
                return true;
            }
        ));
    }

    public function __destruct()
    {
        $this->closeAll();
    }
}
