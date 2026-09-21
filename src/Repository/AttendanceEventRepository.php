<?php

namespace App\Repository;

use App\Entity\AttendanceEvent;
use App\Entity\Employee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AttendanceEvent>
 */
class AttendanceEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendanceEvent::class);
    }

    /** @return AttendanceEvent[] triés chronologiquement */
    public function findForEmployeeOnDate(Employee $employee, \DateTimeImmutable $date): array
    {
        $start = $date->setTime(0, 0, 0);
        $end = $date->setTime(23, 59, 59);

        return $this->createQueryBuilder('e')
            ->andWhere('e.employee = :employee')
            ->andWhere('e.success = true')
            ->andWhere('e.occurredAt BETWEEN :start AND :end')
            ->setParameter('employee', $employee)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('e.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return int[] IDs des employés ayant au moins un event aujourd'hui */
    public function employeeIdsPresentOn(\DateTimeImmutable $date): array
    {
        $start = $date->setTime(0, 0, 0);
        $end = $date->setTime(23, 59, 59);

        $rows = $this->createQueryBuilder('e')
            ->select('DISTINCT IDENTITY(e.employee) as employeeId')
            ->andWhere('e.employee IS NOT NULL')
            ->andWhere('e.occurredAt BETWEEN :start AND :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn ($r) => (int) $r['employeeId'], $rows);
    }

    /** @return AttendanceEvent[] triés chronologiquement, pour l'historique d'un employé sur une plage */
    public function findForEmployeeBetween(Employee $employee, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.employee = :employee')
            ->andWhere('e.success = true')
            ->andWhere('e.occurredAt BETWEEN :start AND :end')
            ->setParameter('employee', $employee)
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(23, 59, 59))
            ->orderBy('e.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return AttendanceEvent[] triés par employé puis chronologiquement, pour le rapport mensuel */
    public function findForAllEmployeesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.success = true')
            ->andWhere('e.employee IS NOT NULL')
            ->andWhere('e.occurredAt BETWEEN :start AND :end')
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(23, 59, 59))
            ->orderBy('e.employee', 'ASC')
            ->addOrderBy('e.occurredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
