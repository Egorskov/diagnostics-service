<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Описывает таблицу inbox_event (SPEC §5) для doctrine:migrations:diff.
 *
 * Inbox — техническая таблица, а не доменная сущность, поэтому вместо ORM-маппинга
 * схема добавляется в момент её генерации — так же Messenger добавляет messenger_messages.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class InboxSchemaListener
{
    public const TABLE = 'inbox_event';

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $table = $schema->createTable(self::TABLE);
        $table->addColumn('event_id', Types::GUID);
        $table->addColumn('event_type', Types::STRING, ['length' => 255]);
        $table->addColumn('received_at', Types::DATETIME_IMMUTABLE, ['comment' => 'UTC']);
        $table->setPrimaryKey(['event_id']);
    }
}
