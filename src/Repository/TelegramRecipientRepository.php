<?php

namespace App\Repository;

use App\Entity\TelegramRecipient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TelegramRecipient>
 */
class TelegramRecipientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TelegramRecipient::class);
    }

    /** @return TelegramRecipient[] */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }
}
