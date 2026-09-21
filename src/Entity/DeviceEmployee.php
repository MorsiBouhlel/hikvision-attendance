<?php

namespace App\Entity;

use App\Repository\DeviceEmployeeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pivot Device <-> Employee, car un même employé peut avoir un employeeNo
 * différent selon la pointeuse sur laquelle il a été enregistré.
 */
#[ORM\Entity(repositoryClass: DeviceEmployeeRepository::class)]
#[ORM\Table(name: 'device_employee')]
#[ORM\UniqueConstraint(name: 'uniq_device_employee_no', columns: ['device_id', 'employee_no'])]
#[ORM\UniqueConstraint(name: 'uniq_device_employee', columns: ['device_id', 'employee_id'])]
class DeviceEmployee
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'deviceEmployees')]
    #[ORM\JoinColumn(nullable: false)]
    private Device $device;

    #[ORM\ManyToOne(targetEntity: Employee::class, inversedBy: 'deviceLinks')]
    #[ORM\JoinColumn(nullable: false)]
    private Employee $employee;

    #[ORM\Column(length: 50)]
    private string $employeeNo;

    public function getId(): ?int { return $this->id; }

    public function getDevice(): Device { return $this->device; }
    public function setDevice(Device $d): static { $this->device = $d; return $this; }

    public function getEmployee(): Employee { return $this->employee; }
    public function setEmployee(Employee $e): static { $this->employee = $e; return $this; }

    public function getEmployeeNo(): string { return $this->employeeNo; }
    public function setEmployeeNo(string $no): static { $this->employeeNo = $no; return $this; }
}
