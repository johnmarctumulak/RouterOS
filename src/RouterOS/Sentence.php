<?php

declare(strict_types=1);

namespace RouterOS;

/**
 * Represents a single RouterOS API sentence (command + attributes + queries).
 *
 * Fluent builder pattern - every mutating method returns $this.
 *
 * Usage:
 *   $sentence = Sentence::command('/ip/address/print')
 *       ->proplist(['address', 'interface', 'network'])
 *       ->query('?type=ether')
 *       ->tag(42);
 */
final class Sentence
{
    private string $command;

    /** @var string[] */
    private array $attributes = [];

    /** @var string[] */
    private array $queries = [];

    /** @var string[] */
    private array $apiAttributes = [];

    private ?int $tag = null;

    private function __construct(string $command)
    {
        $this->command = $command;
    }

    // --- Factory --------------------------------------------------------------

    public static function command(string $command): self
    {
        return new self($command);
    }

    // --- Attribute helpers ----------------------------------------------------

    /**
     * Adds a single attribute word: =name=value
     */
    public function attr(string $name, string $value): self
    {
        $this->attributes[] = "={$name}={$value}";
        return $this;
    }

    /**
     * Adds a .proplist attribute to restrict returned properties (improves performance).
     *
     * @param string[] $properties
     */
    public function proplist(array $properties): self
    {
        return $this->attr('.proplist', implode(',', $properties));
    }

    /**
     * Adds a query word beginning with '?'.
     * The RouterOS API evaluates query words in order, forming a boolean stack.
     *
     * Examples:
     *   ->query('?type=ether')          // property equals value
     *   ->query('?type=vlan')
     *   ->query('?#|')                  // OR the two above
     *   ->query('?>comment=')           // non-empty comment
     */
    public function query(string $queryWord): self
    {
        // Allow callers to pass with or without leading '?'
        if ($queryWord[0] !== '?') {
            $queryWord = '?' . $queryWord;
        }
        $this->queries[] = $queryWord;
        return $this;
    }

    /**
     * Sets the API tag (.tag=N).  When set, every reply sentence for this
     * command will include the same .tag value, enabling concurrent commands.
     */
    public function tag(int $tag): self
    {
        $this->tag = $tag;
        return $this;
    }

    public function getTag(): ?int
    {
        return $this->tag;
    }

    // --- Wire serialization ---------------------------------------------------

    /**
     * Returns the ordered list of words that form this sentence (without the
     * zero-length terminator — Transport adds that).
     *
     * @return string[]
     */
    public function toWords(): array
    {
        $words   = [$this->command];
        $words   = array_merge($words, $this->attributes);
        $words   = array_merge($words, $this->apiAttributes);
        $words   = array_merge($words, $this->queries);

        if ($this->tag !== null) {
            $words[] = ".tag={$this->tag}";
        }

        return $words;
    }
}
