<?php

namespace App\Entity;

use App\Repository\AlertLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace les alertes email déjà envoyées, pour garantir au plus une alerte
 * par employé/jour/type (retard ou absence) — évite le spam en cas de
 * rattrapage de pointages, redémarrage de cron, etc.
 */
#[ORM\Entity(repositoryClass: AlertLogRepository::class)]
#[ORM\Table(name: 'alert_logs')]
#[ORM\UniqueConstraint(name: 'uniq_alert_employee_date_type', columns: ['employee_id', 'date', 'type'])]
class AlertLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Employee::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Employee $employee;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 10)]
    private string $type; // late / absent

    #[ORM\Column]
    private \DateTimeImmutable $sentAt;

    public function getId(): ?int { return $this->id; }

    public function getEmployee(): Employee { return $this->employee; }
    public function setEmployee(Employee $e): static { $this->employee = $e; return $this; }

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $d): static { $this->date = $d; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }

    public function getSentAt(): \DateTimeImmutable { return $this->sentAt; }
    public function setSentAt(\DateTimeImmutable $d): static { $this->sentAt = $d; return $this; }
}
