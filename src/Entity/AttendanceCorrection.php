<?php

namespace App\Entity;

use App\Repository\AttendanceCorrectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'audit d'une correction manuelle de pointage (création, modification
 * ou suppression d'un AttendanceEvent par un admin). Pas de FK vers
 * l'AttendanceEvent lui-même — il peut être supprimé ensuite, l'audit doit
 * survivre à sa cible.
 */
#[ORM\Entity(repositoryClass: AttendanceCorrectionRepository::class)]
#[ORM\Table(name: 'attendance_corrections')]
#[ORM\Index(name: 'idx_correction_employee', columns: ['employee_id'])]
class AttendanceCorrection
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Employee::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Employee $employee;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 20)]
    private string $action;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $attendanceStatus = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmployee(): Employee { return $this->employee; }
    public function setEmployee(Employee $e): static { $this->employee = $e; return $this; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $u): static { $this->user = $u; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $a): static { $this->action = $a; return $this; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $d): static { $this->occurredAt = $d; return $this; }

    public function getAttendanceStatus(): ?string { return $this->attendanceStatus; }
    public function setAttendanceStatus(?string $s): static { $this->attendanceStatus = $s; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $r): static { $this->reason = $r; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
