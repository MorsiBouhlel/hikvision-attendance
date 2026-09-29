<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\LeaveAdjustment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveAdjustment>
 */
class LeaveAdjustmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveAdjustment::class);
    }

    public function sumForEmployee(Employee $employee, int $year): float
    {
        return (float) $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.days), 0)')
            ->andWhere('a.employee = :employee')
            ->andWhere('a.year = :year')
            ->setParameter('employee', $employee)
            ->setParameter('year', $year)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return array<int, float> total des ajustements de l'année, indexé par employee id */
    public function sumsByEmployee(int $year): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.employee) AS employeeId, SUM(a.days) AS total')
            ->andWhere('a.year = :year')
            ->setParameter('year', $year)
            ->groupBy('a.employee')
            ->getQuery()
            ->getScalarResult();

        $sums = [];
        foreach ($rows as $r) {
            $sums[(int) $r['employeeId']] = (float) $r['total'];
        }

        return $sums;
    }

    /** @return LeaveAdjustment[] */
    public function findForEmployee(Employee $employee, int $year): array
    {
        return $this->findBy(['employee' => $employee, 'year' => $year], ['createdAt' => 'DESC']);
    }
}
