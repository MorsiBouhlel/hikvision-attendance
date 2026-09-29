<?php

namespace App\Entity;

use App\Repository\LeaveRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande de congé faite par un employé, à valider par un manager. Une fois
 * approuvée, elle génère un Leave (référencé par $leave) — c'est ce dernier
 * qu'AttendanceService lit, la demande elle-même reste un historique.
 */
#[ORM\Entity(repositoryClass: LeaveRequestRepository::class)]
#[ORM\Table(name: 'leave_requests')]
#[ORM\Index(name: 'idx_leave_request_status', columns: ['status'])]
class LeaveRequest
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
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(length: 30)]
    private string $type = 'conge';

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

    #[ORM\OneToOne(targetEntity: Leave::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Leave $leave = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmployee(): Employee { return $this->employee; }
    public function setEmployee(Employee $e): static { $this->employee = $e; return $this; }

    public function getStartDate(): \DateTimeImmutable { return $this->startDate; }
    public function setStartDate(\DateTimeImmutable $d): static { $this->startDate = $d; return $this; }

    public function getEndDate(): \DateTimeImmutable { return $this->endDate; }
    public function setEndDate(\DateTimeImmutable $d): static { $this->endDate = $d; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $t): static { $this->type = $t; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $r): static { $this->reason = $r; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }

    /** Annulable par l'employé : en attente, ou acceptée mais pas encore commencée. */
    public function isCancellable(): bool
    {
        return $this->isPending()
            || ($this->status === self::STATUS_APPROVED && $this->startDate > new \DateTimeImmutable('today'));
    }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getDecidedBy(): ?User { return $this->decidedBy; }
    public function setDecidedBy(?User $u): static { $this->decidedBy = $u; return $this; }

    public function getDecidedAt(): ?\DateTimeImmutable { return $this->decidedAt; }
    public function setDecidedAt(?\DateTimeImmutable $d): static { $this->decidedAt = $d; return $this; }

    public function getDecisionComment(): ?string { return $this->decisionComment; }
    public function setDecisionComment(?string $c): static { $this->decisionComment = $c; return $this; }

    public function getLeave(): ?Leave { return $this->leave; }
    public function setLeave(?Leave $l): static { $this->leave = $l; return $this; }
}
