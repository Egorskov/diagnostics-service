<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalState;
use App\Domain\Signal\SignalType;
use App\Infrastructure\Persistence\Doctrine\DoctrineSignalStateRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineSignalStateRepositoryTest extends KernelTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const TASK = '33333333-3333-4333-8333-333333333333';

    private EntityManagerInterface $entityManager;
    private DoctrineSignalStateRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM signal_state');
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineSignalStateRepository($this->entityManager);
    }

    private function save(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function stuck(string $emittedAt, Audience $audience = Audience::Student, string $task = self::TASK, bool $active = true): SignalState
    {
        $state = new SignalState(new SignalKey(SignalType::TaskStuck, $audience, self::STUDENT, $task));
        $state->observe(true, new \DateTimeImmutable($emittedAt));
        if (!$active) {
            $state->observe(false, new \DateTimeImmutable($emittedAt));
        }
        $this->repository->add($state);

        return $state;
    }

    public function testRoundTripWithoutSubject(): void
    {
        $key = new SignalKey(SignalType::StudentInactive, Audience::Mentor, self::STUDENT);
        $state = new SignalState($key);
        $state->observe(true, new \DateTimeImmutable('2026-11-03T10:00:00Z'));
        $this->repository->add($state);
        $this->save();

        $found = $this->repository->find($key);

        self::assertNotNull($found);
        self::assertEquals($key, $found->key());
        self::assertTrue($found->isActive());
        self::assertSame('2026-11-03T10:00:00+00:00', $found->firstEmittedAt()?->format(\DATE_ATOM));
        self::assertSame('2026-11-03T10:00:00+00:00', $found->lastEmittedAt()?->format(\DATE_ATOM));
        self::assertNull($this->repository->find(new SignalKey(SignalType::StudentInactive, Audience::Student, self::STUDENT)));
    }

    public function testActiveEmittedBefore(): void
    {
        $due = $this->stuck('2026-11-02T10:00:00Z');
        $this->stuck('2026-11-02T11:00:01Z', task: '44444444-4444-4444-8444-444444444444');
        $this->stuck('2026-11-01T10:00:00Z', task: '55555555-5555-4555-8555-555555555555', active: false);
        $this->stuck('2026-11-01T10:00:00Z', Audience::Mentor);
        $this->save();

        $states = $this->repository->activeEmittedBefore(SignalType::TaskStuck, Audience::Student, new \DateTimeImmutable('2026-11-02T11:00:00Z'));

        self::assertSame([$due->id()], array_map(static fn (SignalState $state) => $state->id(), $states));
    }
}
