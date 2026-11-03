<?php

declare(strict_types=1);

namespace App\Domain\Threshold;

/**
 * Настраиваемый порог (SPEC §5, §7.1). Значение — текст: в §7.1 есть не только числа (ADR 0004).
 */
class Threshold
{
    public function __construct(
        private string $key,
        private string $value,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): string
    {
        return $this->value;
    }
}
