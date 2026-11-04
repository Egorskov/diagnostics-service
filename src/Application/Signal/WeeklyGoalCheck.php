<?php

declare(strict_types=1);

namespace App\Application\Signal;

use App\Application\Port\SignalReadModel;
use App\Application\Port\Thresholds;
use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalType;
use App\Domain\Signal\WeeklyGoal;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * weekly_goal.behind после момента weekly_goal_check текущей недели (SPEC §7.2; design, решение 5).
 * Состояние — по неделе, поэтому ежечасные проверки дают не больше одного сигнала в неделю.
 */
#[AsMessageHandler]
final readonly class WeeklyGoalCheck
{
    public function __construct(
        private SignalReadModel $readModel,
        private Thresholds $thresholds,
        private SignalEmitter $emitter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckWeeklyGoals $check): void
    {
        $now = $this->clock->now();
        $moment = $this->thresholds->weeklyGoalCheck();
        if (!$moment->hasPassed($now)) {
            return;
        }

        $ratio = $this->thresholds->weeklyGoalMinRatio();
        $weekStart = $moment->weekStart($now);
        $logged = $this->readModel->loggedMinutes($weekStart, $weekStart->modify('+6 days'));

        foreach ($this->readModel->studentsWithCurrentPath() as $student) {
            if ($student->weeklyGoalMinutes <= 0) {
                continue;
            }
            $minutes = $logged[$student->studentId] ?? 0;
            $this->emitter->observe(
                new SignalKey(SignalType::WeeklyGoalBehind, Audience::Student, $student->studentId, $weekStart->format('Y-m-d')),
                WeeklyGoal::isBehind($student->weeklyGoalMinutes, $minutes, $ratio),
                $now,
                static fn (): array => [
                    'audience' => Audience::Student->value,
                    'student_id' => $student->studentId,
                    'mentor_id' => $student->mentorId,
                    'goal_minutes' => $student->weeklyGoalMinutes,
                    'logged_minutes' => $minutes,
                    'week_start' => $weekStart->format('Y-m-d'),
                ],
            );
        }
    }
}
