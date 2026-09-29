<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\LeaveRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LeaveRequest>
 */
class LeaveRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LeaveRequest::class);
    }

    /** @return LeaveRequest[] */
    public function findForEmployee(Employee $employee): array
    {
        return $this->findBy(['employee' => $employee], ['createdAt' => 'DESC']);
    }

    /** @return LeaveRequest[] en attente d'abord, puis par date de création décroissante */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN priority")
            ->orderBy('priority', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return LeaveRequest[] demandes en attente d'un employé chevauchant [start, end] */
    public function findPendingOverlapping(Employee $employee, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.employee = :employee')
            ->andWhere('r.status = :status')
            ->andWhere('r.startDate <= :end')
            ->andWhere('r.endDate >= :start')
            ->setParameter('employee', $employee)
            ->setParameter('status', LeaveRequest::STATUS_PENDING)
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();
    }

    /** @return LeaveRequest[] demandes en attente, tous employés, chevauchant [start, end] */
    public function findPendingBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :status')
            ->andWhere('r.startDate <= :end')
            ->andWhere('r.endDate >= :start')
            ->setParameter('status', LeaveRequest::STATUS_PENDING)
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status = :status')
            ->setParameter('status', LeaveRequest::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
