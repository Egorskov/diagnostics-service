<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Progress\SandboxResult;
use App\Domain\Progress\SandboxRun;
use App\Domain\Progress\TaskProgress;
use App\Domain\Progress\TaskStatus;
use App\Domain\Progress\TimeCategory;
use App\Domain\Progress\TimeLog;
use App\Infrastructure\Persistence\Doctrine\DoctrineSandboxRunRepository;
use App\Infrastructure\Persistence\Doctrine\DoctrineTaskProgressRepository;
use App\Infrastructure\Persistence\Doctrine\DoctrineTimeLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineProgressRepositoriesTest extends KernelTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const PATH = '22222222-2222-4222-8222-222222222222';
    private const TASK = '33333333-3333-4333-8333-333333333333';
    private const RUN = '66666666-6666-4666-8666-666666666666';
    private const EVENT = '77777777-7777-4777-8777-777777777777';

    private Connection $connection;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        foreach (['task_progress', 'time_log', 'sandbox_run'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function save(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testFindForUpdateRequiresTransaction(): void
    {
        $repository = new DoctrineTaskProgressRepository($this->entityManager);

        $this->expectException(TransactionRequiredException::class);

        $repository->findForUpdate(self::STUDENT, self::TASK);
    }

    public function testProgressRoundTripInTransaction(): void
    {
        $repository = new DoctrineTaskProgressRepository($this->entityManager);
        $progress = new TaskProgress(self::STUDENT, self::TASK, self::PATH);
        // со смещением: Doctrine пишет время как есть, поэтому приводим к UTC, как PayloadReader
        $progress->start((new \DateTimeImmutable('2026-10-30T12:00:00+01:00'))->setTimezone(new \DateTimeZone('UTC')));
        $progress->recordFailedRun();
        $repository->add($progress);
        $this->save();

        $this->entityManager->wrapInTransaction(static function () use ($repository): void {
            $found = $repository->findForUpdate(self::STUDENT, self::TASK);

            self::assertNotNull($found);
            self::assertSame(self::PATH, $found->pathId());
            self::assertSame(TaskStatus::InProgress, $found->status());
            self::assertSame(1, $found->failedRuns());
            self::assertSame('2026-10-30T11:00:00+00:00', $found->startedAt()?->format(\DATE_ATOM));
            self::assertNull($repository->findForUpdate(self::STUDENT, self::RUN));
        });
    }

    public function testRepeatedFindForUpdateKeepsUnsavedChanges(): void
    {
        $repository = new DoctrineTaskProgressRepository($this->entityManager);
        $repository->add(new TaskProgress(self::STUDENT, self::TASK, self::PATH));
        $this->save();

        $this->entityManager->wrapInTransaction(static function () use ($repository): void {
            $progress = $repository->findForUpdate(self::STUDENT, self::TASK);
            self::assertNotNull($progress);
            $progress->recordFailedRun();

            self::assertSame($progress, $repository->findForUpdate(self::STUDENT, self::TASK));
            self::assertSame(1, $progress->failedRuns());
        });
    }

    public function testFindForUpdateSeesProgressCreatedInSameTransaction(): void
    {
        $repository = new DoctrineTaskProgressRepository($this->entityManager);

        $this->entityManager->wrapInTransaction(static function () use ($repository): void {
            $progress = new TaskProgress(self::STUDENT, self::TASK, self::PATH);
            $progress->recordFailedRun();
            $repository->add($progress);

            self::assertSame($progress, $repository->findForUpdate(self::STUDENT, self::TASK));
            self::assertSame(1, $progress->failedRuns());
        });
    }

    public function testTimeLogKeepsDayAndAllowsEmptyTask(): void
    {
        $repository = new DoctrineTimeLogRepository($this->entityManager);
        $log = new TimeLog(self::STUDENT, null, TimeCategory::Practice, 45, new \DateTimeImmutable('2026-10-30', new \DateTimeZone('UTC')), self::EVENT);
        $repository->add($log);
        $this->save();

        self::assertNotNull($log->id());
        $saved = $this->entityManager->find(TimeLog::class, $log->id());
        self::assertNotNull($saved);
        self::assertNull($saved->taskId());
        self::assertSame(TimeCategory::Practice, $saved->category());
        self::assertSame(45, $saved->minutes());
        self::assertSame('2026-10-30', $saved->loggedForDate()->format('Y-m-d'));
        self::assertSame(self::EVENT, $saved->sourceEventId());
    }

    public function testSandboxRunRoundTrip(): void
    {
        $repository = new DoctrineSandboxRunRepository($this->entityManager);
        $repository->add(new SandboxRun(self::RUN, self::STUDENT, self::TASK, SandboxResult::Failed, 'TypeError', new \DateTimeImmutable('2026-10-30T09:00:00Z')));
        $this->save();

        $run = $repository->find(self::RUN);

        self::assertNotNull($run);
        self::assertSame(SandboxResult::Failed, $run->result());
        self::assertSame('TypeError', $run->errorType());
        self::assertSame('2026-10-30T09:00:00+00:00', $run->occurredAt()->format(\DATE_ATOM));
        self::assertNull($repository->find(self::EVENT));
    }
}
