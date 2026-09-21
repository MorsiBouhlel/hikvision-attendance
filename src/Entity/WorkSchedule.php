<?php

namespace App\Entity;

use App\Repository\WorkScheduleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkScheduleRepository::class)]
#[ORM\Table(name: 'work_schedules')]
class WorkSchedule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $startTime;

    #[ORM\Column(type: 'time_immutable')]
    private \DateTimeImmutable $endTime;

    #[ORM\Column]
    private int $toleranceMinutes = 10;

    #[ORM\Column]
    private int $checkWindowMarginMinutes = 240;

    /** @var Collection<int, Employee> */
    #[ORM\OneToMany(mappedBy: 'workSchedule', targetEntity: Employee::class)]
    private Collection $employees;

    public function __construct()
    {
        $this->employees = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function setStartTime(\DateTimeImmutable $t): static { $this->startTime = $t; return $this; }

    public function getEndTime(): \DateTimeImmutable { return $this->endTime; }
    public function setEndTime(\DateTimeImmutable $t): static { $this->endTime = $t; return $this; }

    public function getToleranceMinutes(): int { return $this->toleranceMinutes; }
    public function setToleranceMinutes(int $m): static { $this->toleranceMinutes = $m; return $this; }

    public function getCheckWindowMarginMinutes(): int { return $this->checkWindowMarginMinutes; }
    public function setCheckWindowMarginMinutes(int $m): static { $this->checkWindowMarginMinutes = $m; return $this; }

    public function crossesMidnight(): bool
    {
        return $this->endTime < $this->startTime;
    }

    /** @return Collection<int, Employee> */
    public function getEmployees(): Collection { return $this->employees; }
}
