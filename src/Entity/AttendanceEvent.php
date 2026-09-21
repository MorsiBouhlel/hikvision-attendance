<?php

namespace App\Entity;

use App\Repository\AttendanceEventRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AttendanceEventRepository::class)]
#[ORM\Table(name: 'attendance_events')]
#[ORM\UniqueConstraint(name: 'uniq_device_serial', columns: ['device_id', 'serial_no'])]
#[ORM\Index(name: 'idx_employee_occurred', columns: ['employee_id', 'occurred_at'])]
class AttendanceEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Device $device;

    #[ORM\ManyToOne(targetEntity: Employee::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Employee $employee = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $employeeNo = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $serialNo = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $verifyMode = null; // face / fingerprint / card / password

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $attendanceStatus = null; // checkIn / checkOut, valeur brute envoyée par la pointeuse quand présente

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $eventType = null;

    #[ORM\Column(nullable: true)]
    private ?int $minor = null;

    #[ORM\Column]
    private bool $success = true;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawPayload = null;

    public function getId(): ?int { return $this->id; }

    public function getDevice(): Device { return $this->device; }
    public function setDevice(Device $d): static { $this->device = $d; return $this; }

    public function getEmployee(): ?Employee { return $this->employee; }
    public function setEmployee(?Employee $e): static { $this->employee = $e; return $this; }

    public function getEmployeeNo(): ?string { return $this->employeeNo; }
    public function setEmployeeNo(?string $n): static { $this->employeeNo = $n; return $this; }

    public function getSerialNo(): ?string { return $this->serialNo; }
    public function setSerialNo(?string $s): static { $this->serialNo = $s; return $this; }

    public function getVerifyMode(): ?string { return $this->verifyMode; }
    public function setVerifyMode(?string $v): static { $this->verifyMode = $v; return $this; }

    public function getAttendanceStatus(): ?string { return $this->attendanceStatus; }
    public function setAttendanceStatus(?string $s): static { $this->attendanceStatus = $s; return $this; }

    public function getEventType(): ?string { return $this->eventType; }
    public function setEventType(?string $t): static { $this->eventType = $t; return $this; }

    public function getMinor(): ?int { return $this->minor; }
    public function setMinor(?int $m): static { $this->minor = $m; return $this; }

    public function isSuccess(): bool { return $this->success; }
    public function setSuccess(bool $s): static { $this->success = $s; return $this; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $d): static { $this->occurredAt = $d; return $this; }

    public function getRawPayload(): ?array { return $this->rawPayload; }
    public function setRawPayload(?array $p): static { $this->rawPayload = $p; return $this; }
}
