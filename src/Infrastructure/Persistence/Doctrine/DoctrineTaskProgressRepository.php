<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\TaskProgressRepository;
use App\Domain\Progress\TaskProgress;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменения сохраняет doctrine_transaction шины: flush здесь не нужен.
 */
final readonly class DoctrineTaskProgressRepository implements TaskProgressRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findForUpdate(string $studentId, string $taskId): ?TaskProgress
    {
        return $this->entityManager->find(TaskProgress::class, ['studentId' => $studentId, 'taskId' => $taskId], LockMode::PESSIMISTIC_WRITE);
    }

    public function add(TaskProgress $progress): void
    {
        $this->entityManager->persist($progress);
    }
}
