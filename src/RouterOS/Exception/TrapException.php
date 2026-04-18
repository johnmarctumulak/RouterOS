<?php

declare(strict_types=1);

namespace RouterOS\Exception;

/**
 * Thrown when the router returns a !trap (command error) sentence.
 * Contains the error message and optional error category.
 */
class TrapException extends RouterOSException
{
    private int $trapCategory;

    /** @var array<string, string> */
    private array $attributes;

    /**
     * @param array<string, string> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes   = $attributes;
        $this->trapCategory = (int) ($attributes['category'] ?? -1);
        $message            = $attributes['message'] ?? 'Unknown trap error';

        parent::__construct($message);
    }

    /**
     * RouterOS error category (0–7).
     * 0 = missing item/command, 1 = argument failure, 2 = interrupted,
     * 3 = scripting, 4 = general, 5 = API, 6 = TTY, 7 = :return value.
     */
    public function getTrapCategory(): int
    {
        return $this->trapCategory;
    }

    /** @return array<string, string> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
