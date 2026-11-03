<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\Signal\CheckStudentInactivity;
use App\Application\Signal\CheckWeeklyGoals;
use App\Application\Signal\EscalateStuckTasks;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Расписание периодических проверок сигналов (SPEC §7.2); время — Europe/Belgrade.
 *
 * Все проверки ежечасные, в начале часа. Недельная тоже: момент weekly_goal_check — порог, и проверка
 * читает его сама при каждом запуске (design add-signals, решение 5).
 */
#[AsSchedule]
final readonly class DiagnosticsSchedule implements ScheduleProviderInterface
{
    public const ZONE = 'Europe/Belgrade';

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        // от полуночи — запуски ровно в начале каждого часа (cron без dragonmantank/cron-expression)
        $from = new \DateTimeImmutable('2026-01-01 00:00', new \DateTimeZone(self::ZONE));

        return (new Schedule())
            ->add(
                RecurringMessage::every('1 hour', new CheckStudentInactivity(), $from),
                RecurringMessage::every('1 hour', new EscalateStuckTasks(), $from),
                RecurringMessage::every('1 hour', new CheckWeeklyGoals(), $from),
            )
            ->stateful($this->cache) // пропущенные запуски выполнятся после рестарта воркера
            ->processOnlyLastMissedRun(true);
    }
}
