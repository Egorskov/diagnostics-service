<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\InvalidThreshold;
use App\Application\Port\Thresholds;
use App\Domain\Threshold\Threshold;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineThresholds implements Thresholds
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function idleTimeoutMinutes(): int
    {
        return $this->positiveInt('idle_timeout_min');
    }

    private function positiveInt(string $key): int
    {
        $threshold = $this->entityManager->find(Threshold::class, $key)
            ?? throw new InvalidThreshold(\sprintf('Threshold "%s" is not set', $key));

        $value = filter_var($threshold->value(), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $value) {
            throw new InvalidThreshold(\sprintf('Threshold "%s" must be a positive integer, got "%s"', $key, $threshold->value()));
        }

        return $value;
    }
}
