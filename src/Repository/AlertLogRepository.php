<?php

namespace App\Repository;

use App\Entity\AlertLog;
use App\Entity\Employee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlertLog>
 */
class AlertLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertLog::class);
    }

    public function alreadySent(Employee $employee, \DateTimeImmutable $date, string $type): bool
    {
        return null !== $this->findOneBy([
            'employee' => $employee,
            'date' => $date->setTime(0, 0, 0),
            'type' => $type,
        ]);
    }
}
