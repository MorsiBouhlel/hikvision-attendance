<?php

namespace App\Entity;

use App\Repository\PermissionRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande d'autorisation : absence de quelques heures sur une journée (rendez-vous, démarche…).
 * Même workflow que LeaveRequest. Déclaratif : décomptée du quota mensuel d'heures, sans effet
 * sur le statut présent/absent ni sur les retards calculés par AttendanceService.
 */
#[ORM\Entity(repositoryClass: PermissionRequestRepository::class)]
#[ORM\Table(name: 'permission_requests')]
#[ORM\Index(name: 'idx_permission_status', columns: ['status'])]
#[ORM\Index(name: 'idx_permission_employee_date', columns: ['employee_id', 'date'])]
class PermissionRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Employee::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Employee $employee;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $endTime;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $decisionComment = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmployee(): Employee { return $this->employee; }
    public function setEmployee(Employee $e): static { $this->employee = $e; return $this; }

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $d): static { $this->date = $d; return $this; }

    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function setStartTime(\DateTimeImmutable $t): static { $this->startTime = $t; return $this; }

    public function getEndTime(): \DateTimeImmutable { return $this->endTime; }
    public function setEndTime(\DateTimeImmutable $t): static { $this->endTime = $t; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $r): static { $this->reason = $r; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }

    /** Durée en heures (2 décimales). */
    public function getHours(): float
    {
        return round((strtotime($this->endTime->format('H:i:s')) - strtotime($this->startTime->format('H:i:s'))) / 3600, 2);
    }

    /** Annulable par l'employé : en attente, ou acceptée mais pas encore passée. */
    public function isCancellable(): bool
    {
        return $this->isPending()
            || ($this->status === self::STATUS_APPROVED && $this->date >= new \DateTimeImmutable('today'));
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function setDecidedBy(?User $u): static { $this->decidedBy = $u; return $this; }

    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function setDecidedAt(?\DateTimeImmutable $d): static { $this->decidedAt = $d; return $this; }

    public function getDecisionComment(): ?string { return $this->decisionComment; }
    public function setDecisionComment(?string $c): static { $this->decisionComment = $c; return $this; }
}
