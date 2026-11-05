<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

use App\Application\Port\Thresholds;

/**
 * История ученика до $now: path.assigned, затем по вечерней сессии в день (design, решение 3).
 * Детерминирована: одинаковые Scenario, $now и пороги дают одинаковый поток, включая event_id (решение 5).
 * Профили подстраиваются под пороги из таблицы threshold, а не под дефолты SPEC (решение 4).
 */
final readonly class ScenarioGenerator
{
    public function __construct(
        private Thresholds $thresholds,
        private string $timezone,
    ) {
    }

    /**
     * @throws \InvalidArgumentException профиль недостижим при этих --days и порогах
     */
    public function generate(Scenario $scenario, \DateTimeImmutable $now): EmulatedStream
    {
        return (new ScenarioRun($scenario, $now, new \DateTimeZone($this->timezone), $this->thresholds))->run();
    }
}
