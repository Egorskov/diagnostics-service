<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103162254 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE signal_state (signal_type VARCHAR(100) NOT NULL, audience VARCHAR(20) NOT NULL, student_id UUID NOT NULL, subject_id VARCHAR(64) DEFAULT NULL, active BOOLEAN NOT NULL, first_emitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_emitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX signal_state_type_audience_active_idx ON signal_state (signal_type, audience, active, last_emitted_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE signal_state');
    }
}
