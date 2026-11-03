<?php

declare(strict_types=1);

namespace App\Application\Signal;

use App\Application\Port\SignalReadModel;
use App\Application\Port\Thresholds;
use App\Domain\Signal\Audience;
use App\Domain\Signal\Inactivity;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * student.inactive: ученику после inactive_student_days, ментору после inactive_mentor_days (SPEC §7.2).
 * Появилась активность — состояния сбрасываются на этой же проверке.
 */
#[AsMessageHandler]
final readonly class StudentInactivityCheck
{
    public function __construct(
        private SignalReadModel $readModel,
        private Thresholds $thresholds,
        private SignalEmitter $emitter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckStudentInactivity $check): void
    {
        $now = $this->clock->now();
        $days = [
            Audience::Student->value => $this->thresholds->inactiveStudentDays(),
            Audience::Mentor->value => $this->thresholds->inactiveMentorDays(),
        ];

        foreach ($this->readModel->studentsWithCurrentPath() as $student) {
            $inactivity = new Inactivity($student->lastActivityAt(), $now);
            foreach ([Audience::Student, Audience::Mentor] as $audience) {
                $this->emitter->observe(
                    new SignalKey(SignalType::StudentInactive, $audience, $student->studentId),
                    $inactivity->reaches($days[$audience->value]),
                    $now,
                    static fn (): array => [
                        'audience' => $audience->value,
                        'student_id' => $student->studentId,
                        'mentor_id' => $student->mentorId,
                        'days_inactive' => $inactivity->days,
                        'last_activity_at' => $inactivity->lastActivityAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_RFC3339),
                    ],
                );
            }
        }
    }
}
