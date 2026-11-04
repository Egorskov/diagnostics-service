<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Signal\SignalEmitter;
use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalType;
use App\Infrastructure\Messenger\MessengerSignalOutbox;
use App\Infrastructure\Persistence\Doctrine\DoctrineSignalStateRepository;
use App\Tests\Support\EventFlowTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * signal_state и outbox на настоящей базе: оба коммитятся и откатываются вместе.
 */
final class SignalEmitterTest extends EventFlowTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';

    private EntityManagerInterface $entityManager;
    private SignalEmitter $emitter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->emitter = new SignalEmitter(
            new DoctrineSignalStateRepository($this->entityManager),
            new MessengerSignalOutbox(self::getContainer()->get(MessageBusInterface::class)),
        );
    }

    private function observe(bool $condition, string $now = '2026-11-03T10:00:00Z'): bool
    {
        return $this->entityManager->wrapInTransaction(fn (): bool => $this->emitter->observe(
            new SignalKey(SignalType::StudentInactive, Audience::Student, self::STUDENT),
            $condition,
            new \DateTimeImmutable($now),
            static fn (): array => ['audience' => 'student', 'student_id' => self::STUDENT],
        ));
    }

    public function testTransitionWritesStateAndOutbox(): void
    {
        self::assertTrue($this->observe(true));

        self::assertSame(1, $this->rowCount('signal_state'));
        self::assertSame(1, $this->outboxCount());
    }

    public function testRepeatedTruthDoesNotWriteSecondSignal(): void
    {
        $this->observe(true);

        self::assertFalse($this->observe(true, '2026-11-03T11:00:00Z'));
        self::assertSame(1, $this->outboxCount());
    }

    public function testFalseConditionCreatesNoState(): void
    {
        self::assertFalse($this->observe(false));

        self::assertSame(0, $this->rowCount('signal_state'));
        self::assertSame(0, $this->outboxCount());
    }

    public function testResetAndNewTransitionWritesSecondSignal(): void
    {
        $this->observe(true);
        $this->observe(false, '2026-11-03T11:00:00Z');

        self::assertTrue($this->observe(true, '2026-11-03T12:00:00Z'));
        self::assertSame(2, $this->outboxCount());
    }

    public function testRollbackLeavesNeitherStateNorSignal(): void
    {
        try {
            $this->entityManager->wrapInTransaction(function (): void {
                $this->emitter->observe(
                    new SignalKey(SignalType::StudentInactive, Audience::Student, self::STUDENT),
                    true,
                    new \DateTimeImmutable('2026-11-03T10:00:00Z'),
                    static fn (): array => [],
                );
                throw new \RuntimeException('Handler failed after the signal was written');
            });
        } catch (\RuntimeException) {
            // без исключения транзакция закоммитилась бы, и проверки ниже упали бы
        }

        self::assertSame(0, $this->rowCount('signal_state'));
        self::assertSame(0, $this->outboxCount());
    }
}
