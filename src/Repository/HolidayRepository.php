<?php

namespace App\Repository;

use App\Entity\Holiday;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Holiday>
 */
class HolidayRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Holiday::class);
    }

    public function isHoliday(\DateTimeImmutable $date): bool
    {
        return null !== $this->findOneBy(['date' => $date->setTime(0, 0, 0)]);
    }

    /** @return \DateTimeImmutable[] dates fériées entre $start et $end (inclus) */
    public function findDatesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $rows = $this->createQueryBuilder('h')
            ->andWhere('h.date BETWEEN :start AND :end')
            ->setParameter('start', $start->setTime(0, 0, 0))
            ->setParameter('end', $end->setTime(0, 0, 0))
            ->getQuery()
            ->getResult();

        return array_map(fn (Holiday $h) => $h->getDate(), $rows);
    }
}
