<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Event\EventMetadata;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

/**
 * Отметки об обработанных входящих событиях (inbox_event, SPEC §5).
 *
 * Вызывается внутри транзакции шины: отметка коммитится или откатывается вместе
 * с изменениями хендлера (ADR 0002, п. 6).
 */
final readonly class DbalInbox
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return bool false — событие с таким event_id уже обработано
     */
    public function markProcessed(EventMetadata $metadata): bool
    {
        // ON CONFLICT вместо SELECT + INSERT: конкурентный дубликат ждёт на уникальном ключе
        // до коммита первой транзакции и получает конфликт, а не проходит проверку одновременно
        $inserted = $this->connection->executeStatement(
            'INSERT INTO '.InboxSchemaListener::TABLE.' (event_id, event_type, received_at) VALUES (:id, :type, :at) ON CONFLICT (event_id) DO NOTHING',
            ['id' => $metadata->eventId, 'type' => $metadata->eventType, 'at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))],
            ['at' => Types::DATETIME_IMMUTABLE],
        );

        return 1 === $inserted;
    }
}
