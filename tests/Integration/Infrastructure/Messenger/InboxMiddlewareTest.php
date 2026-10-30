<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Messenger;

use App\Application\Event\EventMetadata;
use App\Application\Event\Incoming\TaskCompleted;
use App\Infrastructure\Messenger\InboxMiddleware;
use App\Infrastructure\Persistence\Doctrine\DbalInbox;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\Messenger\DoctrineTransactionMiddleware;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Шина как в проде: doctrine_transaction → inbox → handle_message, на тестовой БД.
 */
final class InboxMiddlewareTest extends KernelTestCase
{
    private const EVENT_ID = '0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f';

    private Connection $connection;
    private int $handled = 0;
    private bool $failHandler = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM inbox_event');
        $this->connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS handler_effect (event_id uuid)');
        $this->connection->executeStatement('DELETE FROM handler_effect');
    }

    private function bus(): MessageBus
    {
        $handler = function (TaskCompleted $event): void {
            ++$this->handled;
            // изменение, которое должно коммититься/откатываться вместе с отметкой inbox
            $this->connection->insert('handler_effect', ['event_id' => $event->metadata()->eventId]);
            if ($this->failHandler) {
                throw new \RuntimeException('handler failed');
            }
        };
        $registry = self::getContainer()->get(ManagerRegistry::class);

        return new MessageBus([
            new DoctrineTransactionMiddleware($registry),
            new InboxMiddleware(new DbalInbox($this->connection, new MockClock()), new NullLogger()),
            new HandleMessageMiddleware(new HandlersLocator([TaskCompleted::class => [$handler]])),
        ]);
    }

    private static function received(): Envelope
    {
        $event = new TaskCompleted(
            new EventMetadata(self::EVENT_ID, 'task.completed', 1, new \DateTimeImmutable('2026-10-30T09:00:00Z'), 'courses'),
            studentId: '11111111-1111-4111-8111-111111111111',
            pathId: '22222222-2222-4222-8222-222222222222',
            taskId: '33333333-3333-4333-8333-333333333333',
        );

        return new Envelope($event, [new ReceivedStamp('courses')]);
    }

    private function rows(string $table): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM {$table}");
    }

    public function testDuplicateDeliveryDoesNotCallHandler(): void
    {
        $bus = $this->bus();

        $bus->dispatch(self::received());
        $bus->dispatch(self::received());

        self::assertSame(1, $this->handled);
        self::assertSame(1, $this->rows('inbox_event'));
        self::assertSame(1, $this->rows('handler_effect'));
    }

    public function testHandlerFailureRollsBackInboxMarkTogetherWithChanges(): void
    {
        $this->failHandler = true;
        try {
            $this->bus()->dispatch(self::received());
            self::fail('Handler exception expected');
        } catch (HandlerFailedException) {
        }

        self::assertSame(0, $this->rows('inbox_event'));
        self::assertSame(0, $this->rows('handler_effect'));

        // ретрай после ошибки обрабатывает событие заново
        $this->failHandler = false;
        $this->bus()->dispatch(self::received());

        self::assertSame(2, $this->handled);
        self::assertSame(1, $this->rows('inbox_event'));
        self::assertSame(1, $this->rows('handler_effect'));
    }

    public function testMessagesNotFromBrokerBypassInbox(): void
    {
        $bus = $this->bus();
        $local = self::received()->withoutAll(ReceivedStamp::class);

        $bus->dispatch($local);
        $bus->dispatch($local);

        self::assertSame(2, $this->handled);
        self::assertSame(0, $this->rows('inbox_event'));
    }
}
