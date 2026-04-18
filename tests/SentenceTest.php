<?php

declare(strict_types=1);

namespace RouterOS\Tests;

use PHPUnit\Framework\TestCase;
use RouterOS\Sentence;

/**
 * Tests the Sentence fluent builder word serialization.
 */
final class SentenceTest extends TestCase
{
    public function testCommandOnly(): void
    {
        $words = Sentence::command('/interface/print')->toWords();
        $this->assertSame(['/interface/print'], $words);
    }

    public function testWithAttrs(): void
    {
        $words = Sentence::command('/ip/address/add')
            ->attr('address',   '10.0.0.1/24')
            ->attr('interface', 'ether1')
            ->toWords();

        $this->assertContains('=address=10.0.0.1/24',   $words);
        $this->assertContains('=interface=ether1',       $words);
        $this->assertSame('/ip/address/add',             $words[0]);
    }

    public function testProplist(): void
    {
        $words = Sentence::command('/interface/print')
            ->proplist(['name', 'type', 'running'])
            ->toWords();

        $this->assertContains('=.proplist=name,type,running', $words);
    }

    public function testQueryWords(): void
    {
        $words = Sentence::command('/interface/print')
            ->query('?type=ether')
            ->query('?type=vlan')
            ->query('?#|')
            ->toWords();

        $this->assertContains('?type=ether', $words);
        $this->assertContains('?type=vlan',  $words);
        $this->assertContains('?#|',         $words);
    }

    public function testQueryAutoPrefix(): void
    {
        // Without the leading '?'
        $words = Sentence::command('/interface/print')
            ->query('type=ether')
            ->toWords();

        $this->assertContains('?type=ether', $words);
    }

    public function testTag(): void
    {
        $words = Sentence::command('/ip/route/print')
            ->tag(99)
            ->toWords();

        $this->assertContains('.tag=99', $words);
    }

    public function testTagAccessor(): void
    {
        $sentence = Sentence::command('/foo')->tag(7);
        $this->assertSame(7, $sentence->getTag());
    }

    public function testNoTagByDefault(): void
    {
        $sentence = Sentence::command('/foo');
        $this->assertNull($sentence->getTag());
    }
}
