<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\SandboxRunRepository;
use App\Domain\Progress\SandboxRun;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменения сохраняет doctrine_transaction шины: flush здесь не нужен.
 */
final readonly class DoctrineSandboxRunRepository implements SandboxRunRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $runId): ?SandboxRun
    {
        return $this->entityManager->find(SandboxRun::class, $runId);
    }

    public function add(SandboxRun $run): void
    {
        $this->entityManager->persist($run);
    }
}
