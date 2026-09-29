<?php

namespace App\Entity;

use App\Repository\EmployeeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmployeeRepository::class)]
#[ORM\Table(name: 'employees')]
class Employee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\ManyToOne(targetEntity: Department::class, inversedBy: 'employees')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Department $department = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private bool $isTrackingEnabled = true;

    #[ORM\ManyToOne(targetEntity: WorkSchedule::class, inversedBy: 'employees')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?WorkSchedule $workSchedule = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photoPath = null;

    #[ORM\Column(options: ['default' => 22])]
    private float $annualLeaveDays = 22.0;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $hireDate = null;

    /** Surcharge du quota mensuel de jours de télétravail (null = valeur globale de HrSettings). */
    #[ORM\Column(nullable: true)]
    private ?int $remoteWorkQuotaOverride = null;

    /** Surcharge du quota mensuel d'heures d'autorisation (null = valeur globale de HrSettings). */
    #[ORM\Column(nullable: true)]
    private ?float $permissionQuotaOverride = null;

    /** @var Collection<int, DeviceEmployee> */
    #[ORM\OneToMany(mappedBy: 'employee', targetEntity: DeviceEmployee::class, orphanRemoval: true)]
    private Collection $deviceLinks;

    public function __construct()
    {
        $this->deviceLinks = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $f): static { $this->firstName = $f; return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $l): static { $this->lastName = $l; return $this; }

    public function getDepartment(): ?Department { return $this->department; }
    public function setDepartment(?Department $d): static { $this->department = $d; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $a): static { $this->isActive = $a; return $this; }

    public function isTrackingEnabled(): bool { return $this->isTrackingEnabled; }
    public function setIsTrackingEnabled(bool $t): static { $this->isTrackingEnabled = $t; return $this; }

    public function getWorkSchedule(): ?WorkSchedule { return $this->workSchedule; }
    public function setWorkSchedule(?WorkSchedule $s): static { $this->workSchedule = $s; return $this; }

    public function getPhotoPath(): ?string { return $this->photoPath; }
    public function setPhotoPath(?string $p): static { $this->photoPath = $p; return $this; }

    public function getAnnualLeaveDays(): float { return $this->annualLeaveDays; }
    public function setAnnualLeaveDays(float $d): static { $this->annualLeaveDays = $d; return $this; }

    public function getHireDate(): ?\DateTimeImmutable { return $this->hireDate; }
    public function setHireDate(?\DateTimeImmutable $d): static { $this->hireDate = $d; return $this; }

    public function getRemoteWorkQuotaOverride(): ?int { return $this->remoteWorkQuotaOverride; }
    public function setRemoteWorkQuotaOverride(?int $q): static { $this->remoteWorkQuotaOverride = $q; return $this; }

    public function getPermissionQuotaOverride(): ?float { return $this->permissionQuotaOverride; }
    public function setPermissionQuotaOverride(?float $q): static { $this->permissionQuotaOverride = $q; return $this; }

    public function getFullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /** @return Collection<int, DeviceEmployee> */
    public function getDeviceLinks(): Collection { return $this->deviceLinks; }

    public function employeeNoFor(Device $device): ?string
    {
        foreach ($this->deviceLinks as $link) {
            if ($link->getDevice() === $device) {
                return $link->getEmployeeNo();
            }
        }
        return null;
    }
}
