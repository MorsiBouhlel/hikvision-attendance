<?php

namespace App\Entity;

use App\Repository\DepartmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'departments')]
#[ORM\UniqueConstraint(fields: ['name'])]
class Department
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column]
    private bool $isRemote = false;

    /** @var Collection<int, Employee> */
    #[ORM\OneToMany(mappedBy: 'department', targetEntity: Employee::class)]
    private Collection $employees;

    /** @var Collection<int, DepartmentBreak> */
    #[ORM\OneToMany(mappedBy: 'department', targetEntity: DepartmentBreak::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $breaks;

    public function __construct()
    {
        $this->employees = new ArrayCollection();
        $this->breaks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function isRemote(): bool { return $this->isRemote; }
    public function setIsRemote(bool $isRemote): static { $this->isRemote = $isRemote; return $this; }

    /** @return Collection<int, Employee> */
    public function getEmployees(): Collection { return $this->employees; }

    /** @return Collection<int, DepartmentBreak> */
    public function getBreaks(): Collection { return $this->breaks; }

    public function addBreak(DepartmentBreak $b): static
    {
        if (! $this->breaks->contains($b)) {
            $this->breaks->add($b);
            $b->setDepartment($this);
        }
        return $this;
    }

    public function removeBreak(DepartmentBreak $b): static
    {
        $this->breaks->removeElement($b);
        return $this;
    }

    /** Somme des pauses configurées (café, déjeuner...), déduite automatiquement du temps travaillé — voir AttendanceService::classifyDay(). */
    public function totalBreakMinutes(): int
    {
        return array_sum(array_map(fn (DepartmentBreak $b) => $b->getDurationMinutes(), $this->breaks->toArray()));
    }
}
