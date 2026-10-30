<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Event;

use App\Application\Event\InvalidPayload;
use App\Application\Event\PayloadReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

enum Color: string
{
    case Red = 'red';
    case Green = 'green';
}

final class PayloadReaderTest extends TestCase
{
    private const UUID = '8f1c2b3a-4d5e-4f60-8a7b-9c0d1e2f3a4b';

    /**
     * @return iterable<string, array{array<mixed>, callable(PayloadReader): mixed, mixed}>
     */
    public static function validValues(): iterable
    {
        yield 'string' => [['v' => 'abc'], static fn (PayloadReader $r) => $r->string('v'), 'abc'];
        yield 'optional string absent' => [[], static fn (PayloadReader $r) => $r->optionalString('v'), null];
        yield 'optional string null' => [['v' => null], static fn (PayloadReader $r) => $r->optionalString('v'), null];
        yield 'int' => [['v' => 0], static fn (PayloadReader $r) => $r->int('v'), 0];
        yield 'int at min' => [['v' => 1], static fn (PayloadReader $r) => $r->int('v', min: 1), 1];
        yield 'optional int absent' => [[], static fn (PayloadReader $r) => $r->optionalInt('v'), null];
        yield 'bool' => [['v' => true], static fn (PayloadReader $r) => $r->bool('v', false), true];
        yield 'bool default' => [[], static fn (PayloadReader $r) => $r->bool('v', false), false];
        yield 'uuid lowercased' => [['v' => strtoupper(self::UUID)], static fn (PayloadReader $r) => $r->uuid('v'), self::UUID];
        yield 'optional uuid absent' => [[], static fn (PayloadReader $r) => $r->optionalUuid('v'), null];
        yield 'enum' => [['v' => 'green'], static fn (PayloadReader $r) => $r->enum('v', Color::class), Color::Green];
        yield 'optional enum absent' => [[], static fn (PayloadReader $r) => $r->optionalEnum('v', Color::class), null];
        yield 'date' => [['v' => '2026-10-30'], static fn (PayloadReader $r) => $r->date('v')->format(\DATE_ATOM), '2026-10-30T00:00:00+00:00'];
        yield 'datetime Z' => [['v' => '2026-10-30T12:00:00Z'], static fn (PayloadReader $r) => $r->dateTime('v')->format(\DATE_ATOM), '2026-10-30T12:00:00+00:00'];
        yield 'datetime offset to UTC' => [['v' => '2026-10-30T12:00:00+01:00'], static fn (PayloadReader $r) => $r->dateTime('v')->format(\DATE_ATOM), '2026-10-30T11:00:00+00:00'];
        yield 'datetime fraction' => [['v' => '2026-10-30T12:00:00.250Z'], static fn (PayloadReader $r) => $r->dateTime('v')->format('Y-m-d\TH:i:s.vP'), '2026-10-30T12:00:00.250+00:00'];
        yield 'datetime is UTC' => [['v' => '2026-10-30T12:00:00+03:00'], static fn (PayloadReader $r) => $r->dateTime('v')->getTimezone()->getName(), 'UTC'];
        yield 'nested object' => [['o' => ['v' => 'x']], static fn (PayloadReader $r) => $r->object('o')->string('v'), 'x'];
        yield 'empty list' => [['l' => []], static fn (PayloadReader $r) => $r->list('l'), []];
        yield 'list items' => [['l' => [['v' => 1], ['v' => 2]]], static fn (PayloadReader $r) => array_map(static fn (PayloadReader $i) => $i->int('v'), $r->list('l')), [1, 2]];
    }

    /**
     * @param array<mixed>                   $data
     * @param callable(PayloadReader): mixed $read
     */
    #[DataProvider('validValues')]
    public function testReadsValidValues(array $data, callable $read, mixed $expected): void
    {
        self::assertEquals($expected, $read(new PayloadReader($data)));
    }

    /**
     * @return iterable<string, array{array<mixed>, callable(PayloadReader): mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'missing string' => [[], static fn (PayloadReader $r) => $r->string('v'), 'v: is required'];
        yield 'null required' => [['v' => null], static fn (PayloadReader $r) => $r->string('v'), 'v: is required'];
        yield 'empty string' => [['v' => ''], static fn (PayloadReader $r) => $r->string('v'), 'v: must be a non-empty string'];
        yield 'number as string' => [['v' => 5], static fn (PayloadReader $r) => $r->string('v'), 'v: must be a non-empty string'];
        yield 'string as int' => [['v' => '5'], static fn (PayloadReader $r) => $r->int('v'), 'v: must be an integer'];
        yield 'float as int' => [['v' => 5.0], static fn (PayloadReader $r) => $r->int('v'), 'v: must be an integer'];
        yield 'int below min' => [['v' => 0], static fn (PayloadReader $r) => $r->int('v', min: 1), 'v: must be >= 1'];
        yield 'string as bool' => [['v' => 'true'], static fn (PayloadReader $r) => $r->bool('v', false), 'v: must be a boolean'];
        yield 'not uuid' => [['v' => 'abc'], static fn (PayloadReader $r) => $r->uuid('v'), 'v: must be a UUID'];
        yield 'enum out of range' => [['v' => 'blue'], static fn (PayloadReader $r) => $r->enum('v', Color::class), 'v: must be one of: red, green'];
        yield 'date with time' => [['v' => '2026-10-30T00:00:00Z'], static fn (PayloadReader $r) => $r->date('v'), 'v: must be a date in Y-m-d format'];
        yield 'date overflow' => [['v' => '2026-02-30'], static fn (PayloadReader $r) => $r->date('v'), 'v: must be a date in Y-m-d format'];
        yield 'datetime without tz' => [['v' => '2026-10-30T12:00:00'], static fn (PayloadReader $r) => $r->dateTime('v'), 'v: must be an ISO 8601 date-time with timezone'];
        yield 'datetime overflow' => [['v' => '2026-10-30T25:00:00Z'], static fn (PayloadReader $r) => $r->dateTime('v'), 'v: must be an ISO 8601 date-time with timezone'];
        yield 'object is list' => [['o' => [1, 2]], static fn (PayloadReader $r) => $r->object('o'), 'o: must be an object'];
        yield 'list is object' => [['l' => ['a' => 1]], static fn (PayloadReader $r) => $r->list('l'), 'l: must be an array'];
        yield 'list item not object' => [['l' => [['v' => 1], 'x']], static fn (PayloadReader $r) => $r->list('l'), 'l[1]: must be an object'];
        yield 'nested path' => [
            ['payload' => ['tasks' => [['estimate_minutes' => 10], ['estimate_minutes' => 20], ['estimate_minutes' => 0]]]],
            static fn (PayloadReader $r) => array_map(static fn (PayloadReader $t) => $t->int('estimate_minutes', min: 1), $r->object('payload')->list('tasks')),
            'payload.tasks[2].estimate_minutes: must be >= 1',
        ];
    }

    /**
     * @param array<mixed>                   $data
     * @param callable(PayloadReader): mixed $read
     */
    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(array $data, callable $read, string $message): void
    {
        $this->expectException(InvalidPayload::class);
        $this->expectExceptionMessage($message);

        $read(new PayloadReader($data));
    }
}
