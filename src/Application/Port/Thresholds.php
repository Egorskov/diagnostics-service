<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Пороги SPEC §7.1 из таблицы threshold. Значения в коде нет намеренно (ADR 0004).
 */
interface Thresholds
{
    /**
     * @throws InvalidThreshold
     */
    public function idleTimeoutMinutes(): int;
}
