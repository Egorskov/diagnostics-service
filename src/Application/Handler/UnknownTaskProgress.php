<?php

declare(strict_types=1);

namespace App\Application\Handler;

/**
 * Событию не хватает контекста: нет прогресса по заданию. Это ошибка обработки — транзакция
 * откатывается, дальше работают ретраи и failure transport (ADR 0003, п. 3).
 */
final class UnknownTaskProgress extends \RuntimeException
{
    public function __construct(string $studentId, string $taskId)
    {
        parent::__construct(\sprintf('No progress for student "%s" and task "%s"', $studentId, $taskId));
    }
}
