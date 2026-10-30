<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Messenger;

use App\Application\Event\EventMetadata;
use App\Application\Event\Incoming\TaskCompleted;
use App\Infrastructure\Messenger\DecodeFailure;
use App\Infrastructure\Messenger\FailedMessageLogger;
use App\Infrastructure\Messenger\UndecodableMessage;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class FailedMessageLoggerTest extends TestCase
{
    private const EVENT_ID = '0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f';
    private const CORRELATION_ID = 'c0ffee00-1111-4222-8333-444455556666';

    private RecordingLogger $log;

    protected function setUp(): void
    {
        $this->log = new RecordingLogger();
    }

    private function logger(): FailedMessageLogger
    {
        return new FailedMessageLogger($this->log);
    }

    private static function envelope(): Envelope
    {
        return new Envelope(new TaskCompleted(
            new EventMetadata(self::EVENT_ID, 'task.completed', 1, new \DateTimeImmutable(), 'courses', self::CORRELATION_ID),
            studentId: '11111111-1111-4111-8111-111111111111',
            pathId: '22222222-2222-4222-8222-222222222222',
            taskId: '33333333-3333-4333-8333-333333333333',
        ));
    }

    public function testDoesNotLogWhenMessageWillBeRetried(): void
    {
        $event = new WorkerMessageFailedEvent(self::envelope(), 'courses', new \RuntimeException('db down'));
        $event->setForRetry();

        ($this->logger())($event);

        self::assertSame([], $this->log->records);
    }

    public function testLogsErrorWithEventIdsWhenRetriesAreExhausted(): void
    {
        $envelope = self::envelope();
        $failure = new HandlerFailedException($envelope, [new \RuntimeException('db down')]);

        ($this->logger())(new WorkerMessageFailedEvent($envelope, 'courses', $failure));

        self::assertSame([['level' => 'error', 'message' => 'Message moved to failure transport', 'context' => [
            'event_id' => self::EVENT_ID,
            'event_type' => 'task.completed',
            'correlation_id' => self::CORRELATION_ID,
            'transport' => 'courses',
            'error' => 'db down',
        ]]], $this->log->records);
    }

    public function testLogsDecodeFailureReason(): void
    {
        $message = new UndecodableMessage('{}', DecodeFailure::UnsupportedEventVersion, 'v2', self::EVENT_ID, 'task.completed');

        ($this->logger())(new WorkerMessageFailedEvent(new Envelope($message), 'courses', new \RuntimeException('Undecodable message')));

        self::assertSame('error', $this->log->records[0]['level']);
        self::assertSame(self::EVENT_ID, $this->log->records[0]['context']['event_id']);
        self::assertSame('unsupported_event_version', $this->log->records[0]['context']['decode_failure']);
        self::assertNull($this->log->records[0]['context']['correlation_id']);
    }
}
