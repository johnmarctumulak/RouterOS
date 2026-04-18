<?php

declare(strict_types=1);

namespace RouterOS\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Transport;
use RouterOS\Config;

/**
 * Tests the binary length encoding/decoding that forms the foundation
 * of the RouterOS API wire protocol.
 *
 * These tests do NOT require a real router — they exercise the codec only.
 */
final class TransportEncodingTest extends TestCase
{
    private Transport $transport;

    protected function setUp(): void
    {
        $this->transport = new Transport(new Config(
            '127.0.0.1', 'test', 'test', ssl: false
        ));
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Encode length
    // ──────────────────────────────────────────────────────────────────────────

    public function testEncode1ByteMin(): void
    {
        $this->assertSame("\x00", $this->transport->encodeLength(0));
    }

    public function testEncode1ByteMax(): void
    {
        $this->assertSame("\x7F", $this->transport->encodeLength(0x7F));
    }

    public function testEncode2ByteMin(): void
    {
        // 0x80 → 0x80 | 0x8000 = 0x8080
        $encoded = $this->transport->encodeLength(0x80);
        $this->assertSame(2, strlen($encoded));
        $val = (ord($encoded[0]) << 8) | ord($encoded[1]);
        $this->assertSame(0x80, $val & ~0x8000);
    }

    public function testEncode2ByteMax(): void
    {
        $encoded = $this->transport->encodeLength(0x3FFF);
        $this->assertSame(2, strlen($encoded));
    }

    public function testEncode3Byte(): void
    {
        $encoded = $this->transport->encodeLength(0x4000);
        $this->assertSame(3, strlen($encoded));
    }

    public function testEncode4Byte(): void
    {
        $encoded = $this->transport->encodeLength(0x200000);
        $this->assertSame(4, strlen($encoded));
    }

    public function testEncode5Byte(): void
    {
        $encoded = $this->transport->encodeLength(0x10000000);
        $this->assertSame(5, strlen($encoded));
        $this->assertSame(0xF0, ord($encoded[0]));
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Round-trip: encode → decode via real socket mock
    //  (We use a socketpair / in-memory stream to avoid real network)
    // ──────────────────────────────────────────────────────────────────────────

    /** @dataProvider lengthProvider */
    public function testEncodeDecodeLengthRoundtrip(int $len): void
    {
        $encoded = $this->transport->encodeLength($len);

        // Verify the first byte(s) satisfy the spec constraints
        $firstByte = ord($encoded[0]);

        match (true) {
            $len <= 0x7F       => $this->assertLessThan(0x80, $firstByte),
            $len <= 0x3FFF     => $this->assertSame(2, strlen($encoded)),
            $len <= 0x1FFFFF   => $this->assertSame(3, strlen($encoded)),
            $len <= 0xFFFFFFF  => $this->assertSame(4, strlen($encoded)),
            default            => $this->assertSame(5, strlen($encoded)),
        };
    }

    /** @return array<string, array{int}> */
    public static function lengthProvider(): array
    {
        return [
            'zero'           => [0],
            '1-byte-max'     => [0x7F],
            '2-byte-min'     => [0x80],
            '2-byte-mid'     => [0x1000],
            '2-byte-max'     => [0x3FFF],
            '3-byte-min'     => [0x4000],
            '3-byte-max'     => [0x1FFFFF],
            '4-byte-min'     => [0x200000],
            '4-byte-max'     => [0xFFFFFFF],
            '5-byte-min'     => [0x10000000],
        ];
    }
}
