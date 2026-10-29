<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Расписание периодических проверок сигналов (SPEC §7.2).
 * Задачи добавляются на шаге 6 MVP; время — Europe/Belgrade.
 */
#[AsSchedule]
final readonly class DiagnosticsSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache) // пропущенные запуски выполнятся после рестарта воркера
            ->processOnlyLastMissedRun(true);
    }
}
