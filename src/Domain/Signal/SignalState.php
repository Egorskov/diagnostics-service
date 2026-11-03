<?php

declare(strict_types=1);

namespace App\Domain\Signal;

/**
 * Состояние сигнала для дедупликации (SPEC §5 signal_state, §7.3; ADR 0005, п. 1).
 */
class SignalState
{
    private string $id;
    private SignalType $signalType;
    private Audience $audience;
    private string $studentId;
    private ?string $subjectId;
    private bool $active = false;
    private ?\DateTimeImmutable $firstEmittedAt = null;
    private ?\DateTimeImmutable $lastEmittedAt = null;

    public function __construct(SignalKey $key)
    {
        $this->id = $key->toString();
        $this->signalType = $key->type;
        $this->audience = $key->audience;
        $this->studentId = $key->studentId;
        $this->subjectId = $key->subjectId;
    }

    /**
     * Возвращает true, только когда условие перешло из ложного в истинное: тогда сигнал публикуется.
     */
    public function observe(bool $condition, \DateTimeImmutable $now): bool
    {
        if (!$condition) {
            $this->active = false;

            return false;
        }
        if ($this->active) {
            return false;
        }

        $this->active = true;
        $this->firstEmittedAt ??= $now;
        $this->lastEmittedAt = $now;

        return true;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): SignalKey
    {
        return new SignalKey($this->signalType, $this->audience, $this->studentId, $this->subjectId);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function firstEmittedAt(): ?\DateTimeImmutable
    {
        return $this->firstEmittedAt;
    }

    public function lastEmittedAt(): ?\DateTimeImmutable
    {
        return $this->lastEmittedAt;
    }
}
