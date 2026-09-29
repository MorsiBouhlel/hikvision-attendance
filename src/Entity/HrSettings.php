<?php

namespace App\Entity;

use App\Repository\HrSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/** Ligne unique (singleton) — quotas mensuels par défaut, surchargeables par employé. */
#[ORM\Entity(repositoryClass: HrSettingsRepository::class)]
#[ORM\Table(name: 'hr_settings')]
class HrSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Jours de télétravail autorisés par mois. */
    #[ORM\Column(options: ['default' => 8])]
    private int $remoteWorkDaysPerMonth = 8;

    /** Heures d'autorisation (absence de quelques heures) autorisées par mois. */
    #[ORM\Column(options: ['default' => 4])]
    private float $permissionHoursPerMonth = 4.0;

    public function getId(): ?int { return $this->id; }

    public function getRemoteWorkDaysPerMonth(): int { return $this->remoteWorkDaysPerMonth; }
    public function setRemoteWorkDaysPerMonth(int $d): static { $this->remoteWorkDaysPerMonth = $d; return $this; }

    public function getPermissionHoursPerMonth(): float { return $this->permissionHoursPerMonth; }
    public function setPermissionHoursPerMonth(float $h): static { $this->permissionHoursPerMonth = $h; return $this; }
}
