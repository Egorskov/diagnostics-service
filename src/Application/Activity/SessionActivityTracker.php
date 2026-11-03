<?php

declare(strict_types=1);

namespace App\Application\Activity;

use App\Application\Port\ActivityPoint;
use App\Application\Port\ActivitySessionRepository;
use App\Application\Port\ActivityTracker;
use App\Application\Port\TaskProgressRepository;
use App\Application\Port\Thresholds;
use App\Domain\Progress\ActivitySession;

/**
 * Сессионизация (SPEC §6.1): точка продлевает подходящую сессию или открывает новую,
 * активное время задания пересчитывается как сумма его сессий.
 */
final readonly class SessionActivityTracker implements ActivityTracker
{
    public function __construct(
        private ActivitySessionRepository $sessions,
        private TaskProgressRepository $progress,
        private Thresholds $thresholds,
    ) {
    }

    public function record(ActivityPoint $point): void
    {
        $idleMinutes = $this->thresholds->idleTimeoutMinutes();

        if (null === $point->taskId) {
            $this->apply($point, $this->sessions->withoutTaskNear($point->studentId, $point->occurredAt, $idleMinutes), $idleMinutes);

            return;
        }

        // блокировка до чтения сессий: параллельная точка этого задания увидит результат, а не откроет дубликат
        $progress = $this->progress->findForUpdate($point->studentId, $point->taskId);
        $sessions = $this->apply($point, $this->sessions->forTask($point->studentId, $point->taskId), $idleMinutes);

        $progress?->updateActiveMinutes(array_sum(array_map(static fn (ActivitySession $session) => $session->minutes(), $sessions)));
    }

    /**
     * @param list<ActivitySession> $sessions
     *
     * @return list<ActivitySession> сессии вместе с новой: она ещё не сохранена, и запрос к базе её бы не увидел
     */
    private function apply(ActivityPoint $point, array $sessions, int $idleMinutes): array
    {
        $session = self::latestCovering($sessions, $point->occurredAt, $idleMinutes);
        if (null !== $session) {
            $session->touch($point->occurredAt);

            return $sessions;
        }

        $session = new ActivitySession($point->studentId, $point->taskId, $point->source, $point->occurredAt);
        $this->sessions->add($session);
        $sessions[] = $session;

        return $sessions;
    }

    /**
     * @param list<ActivitySession> $sessions
     */
    private static function latestCovering(array $sessions, \DateTimeImmutable $at, int $idleMinutes): ?ActivitySession
    {
        $latest = null;
        foreach ($sessions as $session) {
            if ($session->covers($at, $idleMinutes) && (null === $latest || $session->lastSeenAt() > $latest->lastSeenAt())) {
                $latest = $session;
            }
        }

        return $latest;
    }
}
