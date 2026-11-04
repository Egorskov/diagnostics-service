<?php

declare(strict_types=1);

namespace App\Domain\Signal;

/**
 * Чем различаются состояния сигналов (ADR 0005, п. 2): предмет — задание у task.stuck,
 * понедельник недели у weekly_goal.behind, пусто у student.inactive.
 */
final readonly class SignalKey
{
    public function __construct(
        public SignalType $type,
        public Audience $audience,
        public string $studentId,
        public ?string $subjectId = null,
    ) {
    }

    public function toString(): string
    {
        return implode('|', [$this->type->value, $this->audience->value, $this->studentId, $this->subjectId ?? '']);
    }
}
