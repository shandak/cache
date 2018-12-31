<?php

namespace Amp\Cache;

use Amp\Struct;
use Concurrent\Task;
use Concurrent\Timer;

final class ArrayCache implements Cache
{
    private $sharedState;
    private $ttlTimer;
    private $maxSize;

    /**
     * @param int $gcInterval The frequency in milliseconds at which expired cache entries should be garbage collected.
     * @param int $maxSize The maximum size of cache array (number of elements).
     */
    public function __construct(int $gcInterval = 5000, int $maxSize = null)
    {
        // By using a shared state object we're able to use `__destruct()` for "normal" garbage collection of both this
        // instance and the loop's watcher. Otherwise this object could only be GC'd when the TTL watcher was cancelled
        // at the loop layer.
        $this->sharedState = $sharedState = new class
        {
            use Struct;

            public $cache = [];
            public $cacheTimeouts = [];
            public $isSortNeeded = false;

            public function collectGarbage(): void
            {
                $now = \time();

                if ($this->isSortNeeded) {
                    \asort($this->cacheTimeouts);
                    $this->isSortNeeded = false;
                }

                foreach ($this->cacheTimeouts as $key => $expiry) {
                    if ($now <= $expiry) {
                        break;
                    }

                    unset(
                        $this->cache[$key],
                        $this->cacheTimeouts[$key]
                    );
                }
            }
        };

        $this->ttlTimer = $ttlTimer = new Timer($gcInterval);

        Task::async(static function () use ($ttlTimer, $sharedState) {
            while (true) {
                $ttlTimer->awaitTimeout();
                $sharedState->collectGarbage();
            }
        });

        $this->maxSize = $maxSize;
    }

    public function __destruct()
    {
        $this->sharedState->cache = [];
        $this->sharedState->cacheTimeouts = [];

        $this->ttlTimer->close();
    }

    /** @inheritdoc */
    public function get(string $key): ?string
    {
        if (!isset($this->sharedState->cache[$key])) {
            return null;
        }

        if (isset($this->sharedState->cacheTimeouts[$key]) && \time() > $this->sharedState->cacheTimeouts[$key]) {
            unset(
                $this->sharedState->cache[$key],
                $this->sharedState->cacheTimeouts[$key]
            );

            return null;
        }

        return $this->sharedState->cache[$key];
    }

    /** @inheritdoc */
    public function set(string $key, string $value, int $ttl = null): void
    {
        if ($ttl === null) {
            unset($this->sharedState->cacheTimeouts[$key]);
        } elseif (\is_int($ttl) && $ttl >= 0) {
            $expiry = \time() + $ttl;
            $this->sharedState->cacheTimeouts[$key] = $expiry;
            $this->sharedState->isSortNeeded = true;
        } else {
            throw new \Error("Invalid cache TTL ({$ttl}; integer >= 0 or null required");
        }

        unset($this->sharedState->cache[$key]);

        if (\count($this->sharedState->cache) === $this->maxSize) {
            \array_shift($this->sharedState->cache);
        }

        $this->sharedState->cache[$key] = $value;
    }

    /** @inheritdoc */
    public function delete(string $key): ?bool
    {
        $exists = isset($this->sharedState->cache[$key]);

        unset(
            $this->sharedState->cache[$key],
            $this->sharedState->cacheTimeouts[$key]
        );

        return $exists;
    }
}
