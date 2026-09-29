<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\RemoteWorkRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RemoteWorkRequest>
 */
class RemoteWorkRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RemoteWorkRequest::class);
    }

    /** @return RemoteWorkRequest[] */
    public function findForEmployee(Employee $employee): array
    {
        return $this->findBy(['employee' => $employee], ['createdAt' => 'DESC']);
    }

    /** @return RemoteWorkRequest[] en attente d'abord, puis par date de création décroissante */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN priority")
            ->orderBy('priority', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return RemoteWorkRequest[] demandes en attente ou acceptées d'un employé chevauchant [start, end] */
    public function findActiveOverlapping(Employee $employee, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.employee = :employee')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.startDate <= :end')
            ->andWhere('r.endDate >= :start')
            ->setParameter('employee', $employee)
            ->setParameter('statuses', [RemoteWorkRequest::STATUS_PENDING, RemoteWorkRequest::STATUS_APPROVED])
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();
    }

    /** @return RemoteWorkRequest[] demandes en attente ou acceptées (tous employés) chevauchant [start, end] */
    public function findActiveBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.startDate <= :end')
            ->andWhere('r.endDate >= :start')
            ->setParameter('statuses', [RemoteWorkRequest::STATUS_PENDING, RemoteWorkRequest::STATUS_APPROVED])
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();
    }
}
