<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\StudentPathRepository;
use App\Domain\Progress\StudentPath;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменения сохраняет doctrine_transaction шины: flush здесь не нужен.
 */
final readonly class DoctrineStudentPathRepository implements StudentPathRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $studentId, string $pathId): ?StudentPath
    {
        return $this->entityManager->find(StudentPath::class, ['studentId' => $studentId, 'pathId' => $pathId]);
    }

    public function add(StudentPath $path): void
    {
        $this->entityManager->persist($path);
    }
}
