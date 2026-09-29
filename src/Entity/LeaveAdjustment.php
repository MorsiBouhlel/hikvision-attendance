<?php

namespace App\Entity;

use App\Repository\LeaveAdjustmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ajustement manuel du solde de congés d'un employé pour une année (report
 * N-1, jours exceptionnels accordés, régularisation). `days` est signé.
 */
#[ORM\Entity(repositoryClass: LeaveAdjustmentRepository::class)]
#[ORM\Table(name: 'leave_adjustments')]
#[ORM\Index(name: 'idx_leave_adjustment_employee_year', columns: ['employee_id', 'year'])]
class LeaveAdjustment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Employee::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Employee $employee;

    #[ORM\Column]
    private int $year;

    #[ORM\Column]
    private float $days;

    #[ORM\Column(length: 255)]
    private string $reason;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmployee(): Employee { return $this->employee; }
    public function setEmployee(Employee $e): static { $this->employee = $e; return $this; }

    public function getYear(): int { return $this->year; }
    public function setYear(int $y): static { $this->year = $y; return $this; }

    public function getDays(): float { return $this->days; }
    public function setDays(float $d): static { $this->days = $d; return $this; }

    public function getReason(): string { return $this->reason; }
    public function setReason(string $r): static { $this->reason = $r; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $u): static { $this->createdBy = $u; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
