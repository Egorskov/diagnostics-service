<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\PathTaskRepository;
use App\Domain\Progress\PathTask;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменения сохраняет doctrine_transaction шины: flush здесь не нужен.
 */
final readonly class DoctrinePathTaskRepository implements PathTaskRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function forPath(string $pathId): array
    {
        return $this->entityManager->getRepository(PathTask::class)->findBy(['pathId' => $pathId], ['position' => 'ASC']);
    }

    public function add(PathTask $task): void
    {
        $this->entityManager->persist($task);
    }

    public function remove(PathTask $task): void
    {
        $this->entityManager->remove($task);
    }
}
