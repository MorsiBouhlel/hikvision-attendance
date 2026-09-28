<?php

namespace App\Repository;

use App\Entity\AttendanceCorrection;
use App\Entity\Employee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AttendanceCorrection>
 */
class AttendanceCorrectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AttendanceCorrection::class);
    }

    /** @return AttendanceCorrection[] triées de la plus récente à la plus ancienne */
    public function findForEmployeeOnDate(Employee $employee, \DateTimeImmutable $date): array
    {
        $start = $date->setTime(0, 0, 0);
        $end = $date->setTime(23, 59, 59);

        return $this->createQueryBuilder('c')
            ->andWhere('c.employee = :employee')
            ->andWhere('c.occurredAt BETWEEN :start AND :end')
            ->setParameter('employee', $employee)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return AttendanceCorrection[] les corrections les plus récentes pour un employé, toutes dates confondues */
    public function findRecentForEmployee(Employee $employee, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.employee = :employee')
            ->setParameter('employee', $employee)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
