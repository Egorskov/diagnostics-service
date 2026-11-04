<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Messenger;

use App\Application\Signal\OutgoingSignal;
use App\Domain\Signal\SignalType;
use App\Infrastructure\Messenger\SignalSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

final class SignalSerializerTest extends TestCase
{
    public function testBodyIsEnvelopeJson(): void
    {
        $signal = OutgoingSignal::create(SignalType::WeeklyGoalBehind, ['audience' => 'student', 'week_start' => '2026-11-02'], new \DateTimeImmutable('2026-11-05T17:00:00Z'));

        $encoded = (new SignalSerializer())->encode(new Envelope($signal));

        self::assertSame(['Content-Type' => 'application/json'], $encoded['headers'] ?? null);
        self::assertSame($signal->envelope(), json_decode($encoded['body'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testRejectsOtherMessages(): void
    {
        $this->expectException(\LogicException::class);

        (new SignalSerializer())->encode(new Envelope(new \stdClass()));
    }

    public function testDoesNotDecode(): void
    {
        $this->expectException(\LogicException::class);

        (new SignalSerializer())->decode(['body' => '{}']);
    }
}
