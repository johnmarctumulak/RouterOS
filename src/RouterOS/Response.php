<?php

declare(strict_types=1);

namespace RouterOS;

use RouterOS\Exception\TrapException;

/**
 * Represents a parsed reply sentence returned by the router.
 *
 * The first word of every reply begins with '!':
 *   !re    – data row
 *   !done  – final reply for a command
 *   !trap  – error
 *   !empty – command completed with no data (RouterOS 7.18+)
 *   !fatal – connection-level error (router shuts down connection after)
 */
final class Response
{
    public const TYPE_RE    = '!re';
    public const TYPE_DONE  = '!done';
    public const TYPE_TRAP  = '!trap';
    public const TYPE_EMPTY = '!empty';
    public const TYPE_FATAL = '!fatal';

    private string $type;

    /** @var array<string, string> */
    private array $attributes;

    private ?int $tag;

    /**
     * @param string[]              $words  Raw words from the router (first word is the type)
     */
    public function __construct(array $words)
    {
        $this->type       = array_shift($words) ?? '';
        $this->attributes = [];
        $this->tag        = null;

        foreach ($words as $word) {
            if (str_starts_with($word, '=')) {
                // attribute word: =name=value
                $eqPos = strpos($word, '=', 1);

                if ($eqPos !== false) {
                    $name  = substr($word, 1, $eqPos - 1);
                    $value = substr($word, $eqPos + 1);
                    $this->attributes[$name] = $value;
                }
            } elseif (str_starts_with($word, '.tag=')) {
                $this->tag = (int) substr($word, 5);
            }
        }
    }

    // --- Accessors ------------------------------------------------------------

    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, string> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, ?string $default = null): ?string
    {
        return $this->attributes[$name] ?? $default;
    }

    public function getTag(): ?int
    {
        return $this->tag;
    }

    // --- Type shortcuts -------------------------------------------------------

    public function isData(): bool
    {
        return $this->type === self::TYPE_RE;
    }

    public function isDone(): bool
    {
        return $this->type === self::TYPE_DONE;
    }

    public function isTrap(): bool
    {
        return $this->type === self::TYPE_TRAP;
    }

    public function isEmpty(): bool
    {
        return $this->type === self::TYPE_EMPTY;
    }

    public function isFatal(): bool
    {
        return $this->type === self::TYPE_FATAL;
    }

    public function isTerminal(): bool
    {
        return $this->isDone() || $this->isTrap() || $this->isEmpty() || $this->isFatal();
    }

    /**
     * Convenience: throws TrapException if this response is a !trap.
     *
     * @throws TrapException
     */
    public function throwIfTrap(): void
    {
        if ($this->isTrap()) {
            throw new TrapException($this->attributes);
        }
    }
}
