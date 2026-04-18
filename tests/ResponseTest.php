<?php

declare(strict_types=1);

namespace RouterOS\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Response;
use RouterOS\Exception\TrapException;

/**
 * Tests the Response sentence parser.
 */
final class ResponseTest extends TestCase
{
    public function testParseDataRow(): void
    {
        $response = new Response(['!re', '=name=ether1', '=type=ether', '=running=yes']);

        $this->assertTrue($response->isData());
        $this->assertFalse($response->isDone());
        $this->assertSame('ether1', $response->getAttribute('name'));
        $this->assertSame('ether',  $response->getAttribute('type'));
        $this->assertSame('yes',    $response->getAttribute('running'));
        $this->assertNull($response->getAttribute('missing-key'));
    }

    public function testParseDone(): void
    {
        $response = new Response(['!done']);
        $this->assertTrue($response->isDone());
        $this->assertTrue($response->isTerminal());
    }

    public function testParseTrap(): void
    {
        $response = new Response(['!trap', '=category=1', '=message=bad argument']);
        $this->assertTrue($response->isTrap());
        $this->assertSame('1', $response->getAttribute('category'));
        $this->assertSame('bad argument', $response->getAttribute('message'));
    }

    public function testThrowIfTrap(): void
    {
        $response = new Response(['!trap', '=category=1', '=message=bad argument']);

        $this->expectException(TrapException::class);
        $this->expectExceptionMessage('bad argument');
        $response->throwIfTrap();
    }

    public function testThrowIfTrapDoesNotThrowOnData(): void
    {
        $response = new Response(['!re', '=name=ether1']);
        $response->throwIfTrap(); // must NOT throw
        $this->assertTrue(true);
    }

    public function testParseTag(): void
    {
        $response = new Response(['!re', '=name=ether1', '.tag=42']);
        $this->assertSame(42, $response->getTag());
    }

    public function testParseNoTag(): void
    {
        $response = new Response(['!re', '=name=ether1']);
        $this->assertNull($response->getTag());
    }

    public function testAttributeWithMultipleEqualSigns(): void
    {
        // Value itself contains "=" — the spec says this is valid
        $response = new Response(['!re', '=name=iu=c3Eeg']);
        $this->assertSame('iu=c3Eeg', $response->getAttribute('name'));
    }

    public function testParseEmpty(): void
    {
        $response = new Response(['!empty']);
        $this->assertTrue($response->isEmpty());
        $this->assertTrue($response->isTerminal());
    }

    public function testParseFatal(): void
    {
        $response = new Response(['!fatal', '=message=session terminated']);
        $this->assertTrue($response->isFatal());
        $this->assertTrue($response->isTerminal());
    }
}
