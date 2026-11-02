<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Результат запуска в песочнице (asyncapi.yaml → SandboxRun.result).
 */
enum SandboxResult: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';
}
