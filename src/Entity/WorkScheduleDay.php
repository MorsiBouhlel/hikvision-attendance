<?php

namespace App\Entity;

use App\Repository\WorkScheduleDayRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkScheduleDayRepository::class)]
#[ORM\Table(name: 'work_schedule_days')]
#[ORM\UniqueConstraint(columns: ['work_schedule_id', 'day_of_week'])]
class WorkScheduleDay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkSchedule::class, inversedBy: 'days')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkSchedule $workSchedule;

    #[ORM\Column]
    private int $dayOfWeek;

    #[ORM\Column]
    private bool $isRestDay = false;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $startTime = null;

    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?\DateTimeImmutable $endTime = null;

    public function getId(): ?int { return $this->id; }

    public function getWorkSchedule(): WorkSchedule { return $this->workSchedule; }
    public function setWorkSchedule(WorkSchedule $s): static { $this->workSchedule = $s; return $this; }

    public function getDayOfWeek(): int { return $this->dayOfWeek; }
    public function setDayOfWeek(int $d): static { $this->dayOfWeek = $d; return $this; }

    public function isRestDay(): bool { return $this->isRestDay; }
    public function setIsRestDay(bool $r): static { $this->isRestDay = $r; return $this; }

    public function getStartTime(): ?\DateTimeImmutable { return $this->startTime; }
    public function setStartTime(?\DateTimeImmutable $t): static { $this->startTime = $t; return $this; }

    public function getEndTime(): ?\DateTimeImmutable { return $this->endTime; }
    public function setEndTime(?\DateTimeImmutable $t): static { $this->endTime = $t; return $this; }

    public function crossesMidnight(): bool
    {
        return $this->startTime !== null && $this->endTime !== null && $this->endTime < $this->startTime;
    }
}
