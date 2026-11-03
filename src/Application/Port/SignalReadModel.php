<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Signal\SignalStudent;

/**
 * Данные для проверок расписания одним запросом на проверку (design, решение 8).
 */
interface SignalReadModel
{
    /**
     * Ученики, у которых есть путь.
     *
     * @return list<SignalStudent>
     */
    public function studentsWithCurrentPath(): array;

    /**
     * Все списания учеников за даты включительно, с заданием и без.
     *
     * @return array<string, int> student_id => минуты
     */
    public function loggedMinutes(\DateTimeImmutable $fromDate, \DateTimeImmutable $toDate): array;
}
