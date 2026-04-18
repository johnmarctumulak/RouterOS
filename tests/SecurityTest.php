<?php

declare(strict_types=1);

namespace RouterOS\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Config;
use RouterOS\Transport;
use RouterOS\Exception\ConnectionException;

class SecurityTest extends TestCase
{
    /**
     * Verifies that the transport layer enforces the maxWordLength safety limit.
     */
    public function testMaxWordLengthEnforcement(): void
    {
        // 1. Setup config with a tiny 1KB limit for testing
        $config = new Config(
            host: '127.0.0.1',
            username: 'admin',
            password: 'password',
            ssl: false,
            maxWordLength: 1024 // 1KB
        );

        $transport = new Transport($config);

        // 2. We need to simulate a router sending a 2KB length byte sequence
        // The word length 2048 (0x0800) in RouterOS encoding (2 bytes) is:
        // 0x8000 | 0x0800 = 0x8800.
        $maliciousLengthBytes = "\x88\x00";

        // Inject into transport's internal buffer via reflection (testing hack)
        $ref = new \ReflectionProperty($transport, 'readBuffer');
        $ref->setAccessible(true);
        $ref->setValue($transport, $maliciousLengthBytes);

        // 3. Attempting to decode the length should immediately throw 
        // without trying to fread() the 2KB body.
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Word length 2048 exceeds safety limit of 1024');

        $decRef = new \ReflectionMethod($transport, 'decodeLength');
        $decRef->setAccessible(true);
        $decRef->invoke($transport);
    }
}
