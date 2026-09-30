<?php

namespace app\service;

/** Streaming RESP2 decoder: TCP chunks need not correspond to Redis messages. */
class RedisRespBuffer
{
    private string $buffer = '';

    public static function command(array $parts): string
    {
        $result = '*' . count($parts) . "\r\n";
        foreach ($parts as $part) $result .= '$' . strlen((string)$part) . "\r\n" . $part . "\r\n";
        return $result;
    }

    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        if (strlen($this->buffer) > 2 * 1024 * 1024) throw new \UnexpectedValueException('Redis frame too large');
        $frames = [];
        while ($this->buffer !== '') {
            $offset = 0;
            [$complete, $value] = $this->parse($offset);
            if (!$complete) break;
            $frames[] = $value;
            $this->buffer = substr($this->buffer, $offset);
        }
        return $frames;
    }

    private function parse(int &$offset, int $depth = 0): array
    {
        if ($depth > 8) throw new \UnexpectedValueException('Redis nesting too deep');
        if ($offset >= strlen($this->buffer)) return [false, null];
        $type = $this->buffer[$offset++];
        $end = strpos($this->buffer, "\r\n", $offset);
        if ($end === false) return [false, null];
        $line = substr($this->buffer, $offset, $end - $offset);
        $offset = $end + 2;
        if ($type === '+') return [true, $line];
        if ($type === '-') throw new \UnexpectedValueException('Redis returned an error');
        if (!in_array($type, [':', '$', '*'], true) || !preg_match('/^-?\d+$/D', $line)) {
            throw new \UnexpectedValueException('Invalid Redis frame');
        }
        $size = (int)$line;
        if ($type === ':') return [true, $size];
        if ($size === -1) return [true, null];
        if ($size < 0 || $size > 2 * 1024 * 1024) throw new \UnexpectedValueException('Invalid Redis frame size');
        if ($type === '$') {
            if (strlen($this->buffer) < $offset + $size + 2) return [false, null];
            $value = substr($this->buffer, $offset, $size);
            $offset += $size;
            if (substr($this->buffer, $offset, 2) !== "\r\n") throw new \UnexpectedValueException('Invalid Redis bulk terminator');
            $offset += 2;
            return [true, $value];
        }
        $values = [];
        for ($i = 0; $i < $size; $i++) {
            [$complete, $value] = $this->parse($offset, $depth + 1);
            if (!$complete) return [false, null];
            $values[] = $value;
        }
        return [true, $values];
    }
}
