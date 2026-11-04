<?php

declare(strict_types=1);

namespace App\Application\Signal;

use App\Application\Port\SignalStateRepository;
use App\Application\Port\Thresholds;
use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Сигнал ученику активен stuck_escalation_hours часов, а задание не закрыто — сигнал ментору (SPEC §7.2).
 */
#[AsMessageHandler]
final readonly class StuckEscalationCheck
{
    public function __construct(
        private SignalStateRepository $states,
        private SignalTaskStuckCheck $stuck,
        private Thresholds $thresholds,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EscalateStuckTasks $check): void
    {
        $due = $this->clock->now()->modify(\sprintf('-%d hours', $this->thresholds->stuckEscalationHours()));

        foreach ($this->states->activeEmittedBefore(SignalType::TaskStuck, Audience::Student, $due) as $state) {
            $key = $state->key();
            if (null !== $key->subjectId) {
                $this->stuck->escalate($key->studentId, $key->subjectId);
            }
        }
    }
}
