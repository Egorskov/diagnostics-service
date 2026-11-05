<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

/**
 * История ученика до $now: path.assigned, затем по вечерней сессии в день (design, решение 3).
 * Детерминирована: одинаковые Scenario и $now дают одинаковый поток, включая event_id (решение 5).
 */
final readonly class ScenarioGenerator
{
    public function __construct(
        private string $timezone,
    ) {
    }

    public function generate(Scenario $scenario, \DateTimeImmutable $now): EmulatedStream
    {
        return (new ScenarioRun($scenario, $now, new \DateTimeZone($this->timezone)))->run();
    }
}
