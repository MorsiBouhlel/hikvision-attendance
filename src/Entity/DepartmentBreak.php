<?php

namespace App\Entity;

use App\Repository\DepartmentBreakRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DepartmentBreakRepository::class)]
#[ORM\Table(name: 'department_breaks')]
class DepartmentBreak
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Department::class, inversedBy: 'breaks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Department $department;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column]
    private int $durationMinutes;

    public function getId(): ?int { return $this->id; }

    public function getDepartment(): Department { return $this->department; }
    public function setDepartment(Department $d): static { $this->department = $d; return $this; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $l): static { $this->label = $l; return $this; }

    public function getDurationMinutes(): int { return $this->durationMinutes; }
    public function setDurationMinutes(int $m): static { $this->durationMinutes = $m; return $this; }
}
