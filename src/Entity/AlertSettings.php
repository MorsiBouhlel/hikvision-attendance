<?php

namespace App\Entity;

use App\Repository\AlertSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/** Ligne unique (singleton) — le switch ON/OFF global des alertes. */
#[ORM\Entity(repositoryClass: AlertSettingsRepository::class)]
#[ORM\Table(name: 'alert_settings')]
class AlertSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private bool $enabled = false;

    public function getId(): ?int { return $this->id; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $e): static { $this->enabled = $e; return $this; }
}
