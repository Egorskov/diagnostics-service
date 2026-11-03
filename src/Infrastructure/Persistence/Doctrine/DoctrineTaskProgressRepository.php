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
        $id = ['studentId' => $studentId, 'taskId' => $taskId];

        // find() с блокировкой перечитал бы уже загруженную сущность из базы и стёр несохранённые
        // изменения хендлера. Такая сущность уже заблокирована в этой транзакции или только что создана.
        $managed = $this->entityManager->getUnitOfWork()->tryGetById($id, TaskProgress::class);
        if ($managed instanceof TaskProgress) {
            return $managed;
        }

        return $this->entityManager->find(TaskProgress::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function add(TaskProgress $progress): void
    {
        $this->entityManager->persist($progress);
    }
}
