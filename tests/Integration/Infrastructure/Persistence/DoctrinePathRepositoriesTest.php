<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Progress\PathTask;
use App\Domain\Progress\StudentPath;
use App\Infrastructure\Persistence\Doctrine\DoctrinePathTaskRepository;
use App\Infrastructure\Persistence\Doctrine\DoctrineStudentPathRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrinePathRepositoriesTest extends KernelTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const PATH = '22222222-2222-4222-8222-222222222222';
    private const OTHER_PATH = '22222222-2222-4222-8222-222222222223';
    private const MENTOR = '44444444-4444-4444-8444-444444444444';
    private const STAGE = '55555555-5555-4555-8555-555555555555';
    private const TASK_1 = '33333333-3333-4333-8333-333333333331';
    private const TASK_2 = '33333333-3333-4333-8333-333333333332';

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM student_path');
        $connection->executeStatement('DELETE FROM path_task');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function save(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testStudentPathRoundTrip(): void
    {
        $repository = new DoctrineStudentPathRepository($this->entityManager);
        $repository->add(new StudentPath(self::STUDENT, self::PATH, self::MENTOR, new \DateTimeImmutable('2026-10-01T08:00:00Z'), 1500));
        $this->save();

        $path = $repository->find(self::STUDENT, self::PATH);

        self::assertNotNull($path);
        self::assertSame(self::MENTOR, $path->mentorId());
        self::assertSame(1500, $path->weeklyGoalMinutes());
        self::assertSame('2026-10-01T08:00:00+00:00', $path->startedAt()->format(\DATE_ATOM));
        self::assertNull($repository->find(self::STUDENT, self::OTHER_PATH));
    }

    public function testTasksAreReturnedForOwnPathInPositionOrder(): void
    {
        $repository = new DoctrinePathTaskRepository($this->entityManager);
        $repository->add(new PathTask(self::PATH, self::TASK_2, self::STAGE, 90, 2));
        $repository->add(new PathTask(self::PATH, self::TASK_1, self::STAGE, 60, 1));
        $repository->add(new PathTask(self::OTHER_PATH, self::TASK_1, self::STAGE, 30, 1));
        $this->save();

        $tasks = $repository->forPath(self::PATH);

        self::assertSame([self::TASK_1, self::TASK_2], array_map(static fn (PathTask $task) => $task->taskId(), $tasks));
        self::assertSame(60, $tasks[0]->estimateMinutes());
    }

    public function testRemovedTaskIsGone(): void
    {
        $repository = new DoctrinePathTaskRepository($this->entityManager);
        $repository->add(new PathTask(self::PATH, self::TASK_1, self::STAGE, 60, 1));
        $this->save();

        $repository->remove($repository->forPath(self::PATH)[0]);
        $this->save();

        self::assertSame([], $repository->forPath(self::PATH));
    }
}
