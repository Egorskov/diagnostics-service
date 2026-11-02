<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\TimeLogRepository;
use App\Domain\Progress\TimeLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменения сохраняет doctrine_transaction шины: flush здесь не нужен.
 */
final readonly class DoctrineTimeLogRepository implements TimeLogRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(TimeLog $log): void
    {
        $this->entityManager->persist($log);
    }
}
