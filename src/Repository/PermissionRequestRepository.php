<?php

namespace App\Repository;

use App\Entity\Employee;
use App\Entity\PermissionRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PermissionRequest>
 */
class PermissionRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PermissionRequest::class);
    }

    /** @return PermissionRequest[] */
    public function findForEmployee(Employee $employee): array
    {
        return $this->findBy(['employee' => $employee], ['date' => 'DESC', 'startTime' => 'DESC']);
    }

    /** @return PermissionRequest[] en attente d'abord, puis par date de création décroissante */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN priority")
            ->orderBy('priority', 'ASC')
            ->addOrderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return PermissionRequest[] demandes en attente ou acceptées d'un employé sur [start, end] (dates incluses) */
    public function findActiveBetween(Employee $employee, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.employee = :employee')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('r.date BETWEEN :start AND :end')
            ->setParameter('employee', $employee)
            ->setParameter('statuses', [PermissionRequest::STATUS_PENDING, PermissionRequest::STATUS_APPROVED])
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();
    }
}
