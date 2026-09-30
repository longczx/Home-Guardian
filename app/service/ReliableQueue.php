<?php

namespace app\service;

/** Single-consumer list queue. Pending work survives reconnects and worker restarts. */
class ReliableQueue
{
    public function __construct(private \Redis $redis, private string $ready, private string $processing) {}

    public function reserve(int $limit): array
    {
        $pending = $this->redis->lRange($this->processing, -$limit, -1);
        if ($pending) return array_reverse($pending);
        $items = [];
        for ($i = 0; $i < $limit; $i++) {
            $raw = $this->redis->rPopLPush($this->ready, $this->processing);
            if ($raw === false || $raw === null) break;
            $items[] = $raw;
        }
        return $items;
    }

    public function acknowledge(string $raw): void
    {
        $this->redis->lRem($this->processing, $raw, 1);
    }

    public function move(string $raw, string $destination, ?string $replacement = null): void
    {
        $this->redis->eval(
            "if redis.call('LREM', KEYS[1], 1, ARGV[1]) == 1 then redis.call('LPUSH', KEYS[2], ARGV[2]); return 1 end return 0",
            [$this->processing, $destination, $raw, $replacement ?? $raw], 2
        );
    }
}
