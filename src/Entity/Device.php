<?php

namespace App\Entity;

use App\Repository\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'devices')]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $site = null;

    #[ORM\Column(length: 45)]
    private string $ipAddress;

    #[ORM\Column]
    private int $port = 80;

    #[ORM\Column(length: 50)]
    private string $adminUser = 'admin';

    // Stocké chiffré au niveau applicatif (voir HikvisionDigestClient / un Doctrine type custom si tu veux du chiffrement transparent)
    #[ORM\Column(type: 'text')]
    private string $adminPassword;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(length: 256, unique: true)]
    private string $webhookToken;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    /** @var Collection<int, DeviceEmployee> */
    #[ORM\OneToMany(mappedBy: 'device', targetEntity: DeviceEmployee::class, orphanRemoval: true)]
    private Collection $deviceEmployees;

    public function __construct()
    {
        $this->deviceEmployees = new ArrayCollection();
        $this->webhookToken = bin2hex(random_bytes(16));
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getSite(): ?string { return $this->site; }
    public function setSite(?string $site): static { $this->site = $site; return $this; }

    public function getIpAddress(): string { return $this->ipAddress; }
    public function setIpAddress(string $ip): static { $this->ipAddress = $ip; return $this; }

    public function getPort(): int { return $this->port; }
    public function setPort(int $port): static { $this->port = $port; return $this; }

    public function getAdminUser(): string { return $this->adminUser; }
    public function setAdminUser(string $u): static { $this->adminUser = $u; return $this; }

    public function getAdminPassword(): string { return $this->adminPassword; }
    public function setAdminPassword(string $p): static { $this->adminPassword = $p; return $this; }

    public function getSerialNumber(): ?string { return $this->serialNumber; }
    public function setSerialNumber(?string $s): static { $this->serialNumber = $s; return $this; }

    public function getWebhookToken(): string { return $this->webhookToken; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $a): static { $this->isActive = $a; return $this; }

    public function getLastSeenAt(): ?\DateTimeImmutable { return $this->lastSeenAt; }
    public function setLastSeenAt(?\DateTimeImmutable $d): static { $this->lastSeenAt = $d; return $this; }

    public function getBaseUrl(): string
    {
        return sprintf('http://%s:%d', $this->ipAddress, $this->port);
    }

    /** @return Collection<int, DeviceEmployee> */
    public function getDeviceEmployees(): Collection { return $this->deviceEmployees; }
}
