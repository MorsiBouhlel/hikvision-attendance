<?php

namespace App\Repository;

use App\Entity\HrSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HrSettings>
 */
class HrSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HrSettings::class);
    }

    /** Crée la ligne singleton au premier accès si elle n'existe pas encore. */
    public function getOrCreate(): HrSettings
    {
        $settings = $this->findOneBy([]);

        if (! $settings) {
            $settings = new HrSettings();
            $em = $this->getEntityManager();
            $em->persist($settings);
            $em->flush();
        }

        return $settings;
    }
}
