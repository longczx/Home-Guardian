<?php

namespace Tests\Unit;

use app\service\RedisRespBuffer;
use PHPUnit\Framework\TestCase;

class RedisRespBufferTest extends TestCase
{
    public function test_split_and_coalesced_pubsub_frames_preserve_every_payload(): void
    {
        $one = "{\"type\":\"command_reply\",\"text\":\"设备确认\"}\r\n";
        $two = '{"type":"telemetry"}';
        $wire = "+OK\r\n" . RedisRespBuffer::command(['message', 'ws:broadcast', $one])
            . RedisRespBuffer::command(['message', 'ws:broadcast', $two]);
        $decoder = new RedisRespBuffer();
        $received = [];
        foreach (str_split($wire, 7) as $chunk) $received = array_merge($received, $decoder->feed($chunk));
        self::assertSame(['OK', ['message', 'ws:broadcast', $one], ['message', 'ws:broadcast', $two]], $received);
        self::assertSame([], $decoder->feed(''));
    }

    public function test_subscribe_integer_reply_and_binary_password_encoding(): void
    {
        $decoder = new RedisRespBuffer();
        self::assertSame([['subscribe', 'ws:broadcast', 1]], $decoder->feed("*3\r\n$9\r\nsubscribe\r\n$12\r\nws:broadcast\r\n:1\r\n"));
        self::assertSame([['AUTH', "a b\r\nc"]], $decoder->feed(RedisRespBuffer::command(['AUTH', "a b\r\nc"])));
    }
}
