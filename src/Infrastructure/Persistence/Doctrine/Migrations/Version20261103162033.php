<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Написана руками: diff сравнивает схемы и данные породить не может (ADR 0004, п. 5).
 */
final class Version20261103162033 extends AbstractMigration
{
    private const THRESHOLDS = [
        'inactive_student_days' => '3',
        'inactive_mentor_days' => '7',
        'weekly_goal_check' => 'четверг 18:00 Europe/Belgrade',
        'weekly_goal_min_ratio' => '0.5',
        'stuck_time_ratio' => '1.5',
        'stuck_failed_runs' => '10',
        'stuck_escalation_hours' => '24',
    ];

    public function getDescription(): string
    {
        return 'Начальные значения порогов сигналов student.inactive, task.stuck, weekly_goal.behind (SPEC §7.1)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::THRESHOLDS as $key => $value) {
            $this->addSql('INSERT INTO threshold (key, value) VALUES (:key, :value) ON CONFLICT (key) DO NOTHING', ['key' => $key, 'value' => $value]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM threshold WHERE key IN (:keys)', ['keys' => array_keys(self::THRESHOLDS)], ['keys' => ArrayParameterType::STRING]);
    }
}
