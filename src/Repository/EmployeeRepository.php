<?php

namespace App\Repository;

use App\Entity\Employee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Employee>
 */
class EmployeeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Employee::class);
    }

    /** @return Employee[] employés actifs ET concernés par le pointage (isTrackingEnabled) */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => true, 'isTrackingEnabled' => true]);
    }

    /** @return Employee[] tous les employés listables dans /employees (exclut ceux non concernés par le pointage, garde actifs et inactifs) */
    public function findListable(): array
    {
        return $this->findBy(['isTrackingEnabled' => true]);
    }
}
