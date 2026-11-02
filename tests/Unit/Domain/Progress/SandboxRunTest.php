<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Progress;

use App\Domain\Progress\SandboxResult;
use App\Domain\Progress\SandboxRun;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SandboxRunTest extends TestCase
{
    #[DataProvider('results')]
    public function testFailedIsEverythingExceptPassed(SandboxResult $result, bool $failed): void
    {
        $run = new SandboxRun('run', 'student', 'task', $result, null, new \DateTimeImmutable('2026-10-30T09:00:00Z'));

        self::assertSame($failed, $run->isFailed());
    }

    /**
     * @return iterable<string, array{SandboxResult, bool}>
     */
    public static function results(): iterable
    {
        yield 'passed' => [SandboxResult::Passed, false];
        yield 'failed' => [SandboxResult::Failed, true];
        yield 'error' => [SandboxResult::Error, true];
    }
}
