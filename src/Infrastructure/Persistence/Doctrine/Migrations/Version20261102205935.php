<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Написана руками: diff сравнивает схемы и данные породить не может (ADR 0004, п. 5).
 */
final class Version20261102205935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Начальное значение порога idle_timeout_min (SPEC §7.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO threshold (key, value) VALUES ('idle_timeout_min', '15') ON CONFLICT (key) DO NOTHING");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM threshold WHERE key = 'idle_timeout_min'");
    }
}
