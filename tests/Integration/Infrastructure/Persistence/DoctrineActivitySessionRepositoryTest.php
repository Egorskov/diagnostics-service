<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Progress\ActivitySession;
use App\Domain\Progress\ActivitySource;
use App\Infrastructure\Persistence\Doctrine\DoctrineActivitySessionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineActivitySessionRepositoryTest extends KernelTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const OTHER_STUDENT = '11111111-1111-4111-8111-111111111112';
    private const TASK = '33333333-3333-4333-8333-333333333333';
    private const OTHER_TASK = '33333333-3333-4333-8333-333333333334';

    private EntityManagerInterface $entityManager;
    private DoctrineActivitySessionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM activity_session');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineActivitySessionRepository($this->entityManager);
    }

    private function addSession(string $studentId, ?string $taskId, string $at = '2026-10-30T10:00:00Z'): void
    {
        $this->repository->add(new ActivitySession($studentId, $taskId, ActivitySource::Theory, new \DateTimeImmutable($at)));
    }

    private function save(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    public function testForTaskReturnsOnlyOwnSessions(): void
    {
        $this->addSession(self::STUDENT, self::TASK);
        $this->addSession(self::STUDENT, self::TASK, '2026-10-30T11:00:00Z');
        $this->addSession(self::STUDENT, self::OTHER_TASK);
        $this->addSession(self::STUDENT, null);
        $this->addSession(self::OTHER_STUDENT, self::TASK);
        $this->save();

        $sessions = $this->repository->forTask(self::STUDENT, self::TASK);

        self::assertCount(2, $sessions);
        self::assertSame(ActivitySource::Theory, $sessions[0]->source());
        self::assertSame('2026-10-30T10:00:00+00:00', $sessions[0]->startedAt()->format(\DATE_ATOM));
    }

    #[DataProvider('windows')]
    public function testWithoutTaskNearRespectsWindow(string $at, int $expected): void
    {
        $this->addSession(self::STUDENT, null);
        $this->save();

        self::assertCount($expected, $this->repository->withoutTaskNear(self::STUDENT, new \DateTimeImmutable($at), 15));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function windows(): iterable
    {
        yield 'ровно через таймаут' => ['2026-10-30T10:15:00Z', 1];
        yield 'позже таймаута' => ['2026-10-30T10:15:01Z', 0];
        yield 'раньше ровно на таймаут' => ['2026-10-30T09:45:00Z', 1];
        yield 'раньше больше таймаута' => ['2026-10-30T09:44:59Z', 0];
    }

    public function testWithoutTaskNearSkipsTaskAndOtherStudentSessions(): void
    {
        $this->addSession(self::STUDENT, self::TASK);
        $this->addSession(self::OTHER_STUDENT, null);
        $this->save();

        self::assertSame([], $this->repository->withoutTaskNear(self::STUDENT, new \DateTimeImmutable('2026-10-30T10:00:00Z'), 15));
    }
}
