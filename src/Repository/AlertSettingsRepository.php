<?php

namespace App\Repository;

use App\Entity\AlertSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlertSettings>
 */
class AlertSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertSettings::class);
    }

    /** Crée la ligne singleton au premier accès si elle n'existe pas encore. */
    public function getOrCreate(): AlertSettings
    {
        $settings = $this->findOneBy([]);

        if (! $settings) {
            $settings = new AlertSettings();
            $em = $this->getEntityManager();
            $em->persist($settings);
            $em->flush();
        }

        return $settings;
    }

    public function isEnabled(): bool
    {
        return $this->getOrCreate()->isEnabled();
    }
}
