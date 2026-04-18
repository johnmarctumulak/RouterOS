<?php

declare(strict_types=1);

namespace RouterOS;

use RouterOS\Exception\TrapException;
use RouterOS\Exception\ConnectionException;

/**
 * Fluent query builder returned by Client::query().
 *
 * Wraps Sentence construction with a readable, chainable API.
 *
 * Example:
 *   $interfaces = $client->query('/interface/print')
 *       ->proplist(['name', 'type', 'running', 'mac-address'])
 *       ->where('type', 'ether')
 *       ->orWhere('type', 'vlan')
 *       ->fetch();
 */
final class QueryBuilder
{
    private Client $client;
    private Sentence $sentence;

    /** @var bool  Track whether we need to emit an OR operator after multiple orWhere() chains */
    private int $orPendingCount = 0;

    public function __construct(Client $client, string $command)
    {
        $this->client   = $client;
        $this->sentence = Sentence::command($command);
    }

    // --- Attribute words ------------------------------------------------------

    /**
     * Adds an attribute word: =name=value
     */
    public function attr(string $name, string $value): self
    {
        $this->sentence->attr($name, $value);
        return $this;
    }

    /**
     * Restricts returned properties for performance.
     *
     * @param string[] $properties
     */
    public function proplist(array $properties): self
    {
        $this->sentence->proplist($properties);
        return $this;
    }

    // --- Query words ----------------------------------------------------------

    /**
     * Property equals value: ?name=value  (AND semantics by default)
     */
    public function where(string $property, string $value): self
    {
        $this->sentence->query("?{$property}={$value}");
        return $this;
    }

    /**
     * Property greater than value: ?>name=value
     */
    public function whereGt(string $property, string $value): self
    {
        $this->sentence->query("?>{$property}={$value}");
        return $this;
    }

    /**
     * Property less than value: ?<name=value
     */
    public function whereLt(string $property, string $value): self
    {
        $this->sentence->query("?<{$property}={$value}");
        return $this;
    }

    /**
     * Property has any value (exists): ?name
     */
    public function whereExists(string $property): self
    {
        $this->sentence->query("?{$property}");
        return $this;
    }

    /**
     * Property has no value (missing): ?-name
     */
    public function whereNotExists(string $property): self
    {
        $this->sentence->query("?-{$property}");
        return $this;
    }

    /**
     * Chains an OR alternative to the immediately preceding where condition.
     *
     * Emits: ?name=value followed by ?#|
     *
     * Example:
     *   ->where('type','ether')->orWhere('type','vlan')
     *   emits: ?type=ether ?type=vlan ?#|
     */
    public function orWhere(string $property, string $value): self
    {
        $this->sentence->query("?{$property}={$value}");
        $this->sentence->query('?#|');
        return $this;
    }

    /**
     * Negate the top stack value: ?#!
     */
    public function whereNot(): self
    {
        $this->sentence->query('?#!');
        return $this;
    }

    /**
     * Append a raw query word (for advanced query stack operations).
     */
    public function rawQuery(string $queryWord): self
    {
        $this->sentence->query($queryWord);
        return $this;
    }

    // --- Tag support ----------------------------------------------------------

    public function tag(int $tag): self
    {
        $this->sentence->tag($tag);
        return $this;
    }

    // --- Terminal actions -----------------------------------------------------

    /**
     * Executes the command and returns all data rows.
     *
     * @return array<int, array<string, string>>
     * @throws TrapException
     * @throws ConnectionException
     */
    public function fetch(): array
    {
        return $this->client->sendAndCollect($this->sentence);
    }

    /**
     * Executes the command and returns only the first data row (or null).
     *
     * @return array<string, string>|null
     * @throws TrapException
     * @throws ConnectionException
     */
    public function first(): ?array
    {
        $rows = $this->fetch();
        return $rows[0] ?? null;
    }

    /**
     * Returns the underlying Sentence (for introspection / testing).
     */
    public function getSentence(): Sentence
    {
        return $this->sentence;
    }
}
