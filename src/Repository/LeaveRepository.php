<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\Leave;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Leave>
 */
class LeaveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Leave::class);
    }

    /** @return int[] IDs des employés en congé à cette date */
    public function employeeIdsOnLeave(\DateTimeImmutable $date): array
    {
        $date = $date->setTime(0, 0, 0);

        $rows = $this->createQueryBuilder('l')
            ->select('DISTINCT IDENTITY(l.employee) as employeeId')
            ->andWhere('l.startDate <= :date')
            ->andWhere('l.endDate >= :date')
            ->setParameter('date', $date)
            ->getQuery()
            ->getScalarResult();

        return array_map(fn ($r) => (int) $r['employeeId'], $rows);
    }

    /** @return Leave[] triés par date de début (plus récent d'abord) */
    public function findForEmployee(Employee $employee): array
    {
        return $this->findBy(['employee' => $employee], ['startDate' => 'DESC']);
    }

    /** @return Leave[] congés chevauchant [start, end], toutes personnes confondues */
    public function findOverlapping(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.startDate <= :end')
            ->andWhere('l.endDate >= :start')
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();
    }

    /** @return Leave[] congés d'un employé chevauchant [start, end] */
    public function findOverlappingForEmployee(Employee $employee, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.employee = :employee')
            ->andWhere('l.startDate <= :end')
            ->andWhere('l.endDate >= :start')
            ->setParameter('employee', $employee)
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();
    }
}
