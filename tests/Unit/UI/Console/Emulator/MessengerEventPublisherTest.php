<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console\Emulator;

use App\Infrastructure\Messenger\EnvelopeSerializer;
use App\Infrastructure\Messenger\EventTypeRegistry;
use App\UI\Console\Emulator\EmulatedEvent;
use App\UI\Console\Emulator\MessengerEventPublisher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MessengerEventPublisherTest extends TestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const TASK = '33333333-3333-4333-8333-000000000001';

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function events(): iterable
    {
        yield 'task.started' => ['task.started', ['student_id' => self::STUDENT, 'path_id' => '22222222-2222-4222-8222-222222222222', 'task_id' => self::TASK], 'courses'];
        yield 'sandbox.run' => ['sandbox.run', ['run_id' => '77777777-7777-4777-8777-777777777777', 'student_id' => self::STUDENT, 'task_id' => self::TASK, 'result' => 'passed'], 'courses'];
        yield 'time.logged' => ['time.logged', ['student_id' => self::STUDENT, 'minutes' => 30, 'category' => 'interview', 'logged_for_date' => '2026-11-04'], 'time'];
        yield 'activity.heartbeat' => ['activity.heartbeat', ['student_id' => self::STUDENT, 'source' => 'theory'], 'heartbeat'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('events')]
    public function testSendsRawBodyToTransportOfEventType(string $type, array $payload, string $transport): void
    {
        $transports = ['courses' => new InMemoryTransport(), 'time' => new InMemoryTransport(), 'heartbeat' => new InMemoryTransport()];
        $event = new EmulatedEvent('66666666-6666-4666-8666-000000000001', $type, new \DateTimeImmutable('2026-11-04T17:12:00Z'), $payload);

        (new MessengerEventPublisher(...$transports))->publish($event);

        foreach ($transports as $name => $memory) {
            self::assertCount($name === $transport ? 1 : 0, $memory->getSent(), $name);
        }
        $sent = $transports[$transport]->getSent()[0];
        self::assertSame($type, $sent->last(AmqpStamp::class)?->getRoutingKey());
        self::assertSame(
            ['body' => $event->json(), 'headers' => ['Content-Type' => 'application/json']],
            (new EnvelopeSerializer(new EventTypeRegistry()))->encode($sent),
        );
    }
}
