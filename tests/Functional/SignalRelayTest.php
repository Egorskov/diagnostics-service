<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Signal\OutgoingSignal;
use App\Domain\Signal\SignalType;
use App\Tests\Support\EventFlowTestCase;
use App\Tests\Support\FlakySignalPublisher;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Outbox (Doctrine, тестовая база) → воркер → relay → транспорт signals (in-memory вместо diagnostics.events).
 */
final class SignalRelayTest extends EventFlowTestCase
{
    private static function signal(): OutgoingSignal
    {
        return OutgoingSignal::create(SignalType::WeeklyGoalBehind, ['audience' => 'student'], new \DateTimeImmutable('2026-11-05T17:00:00Z'));
    }

    public function testRelayPublishesWithRoutingKey(): void
    {
        $signal = self::signal();
        self::getContainer()->get(MessageBusInterface::class)->dispatch($signal);
        self::assertSame(1, $this->outboxCount());
        self::assertSame([], self::transport('signals')->getSent());

        self::consume(1, ['outbox']);

        $published = self::publishedSignals();
        self::assertCount(1, $published);
        self::assertSame($signal->envelope(), $published[0]->envelope());
        self::assertSame(0, $this->outboxCount());
    }

    public function testFailedPublishIsRetriedWithSameEventId(): void
    {
        $signal = self::signal();
        self::getContainer()->get(MessageBusInterface::class)->dispatch($signal);
        FlakySignalPublisher::$failures = 1;

        // первая попытка падает, ретрай через 1 с публикует тот же конверт
        self::consume(2, ['outbox']);

        $published = self::publishedSignals();
        self::assertCount(1, $published);
        self::assertSame($signal->eventId, $published[0]->eventId);
        self::assertSame(0, $this->outboxCount());
        self::assertSame([], self::transport('failed')->getSent());
    }
}
