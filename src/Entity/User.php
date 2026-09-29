<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column(length: 5)]
    private string $locale = 'fr';

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $resetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    public function getId(): ?int { return $this->id; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $e): static { $this->email = $e; return $this; }

    public function getUserIdentifier(): string { return $this->email; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $p): static { $this->password = $p; return $this; }

    public function getRoles(): array
    {
        return array_unique([...$this->roles, 'ROLE_VIEWER']);
    }

    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $a): static { $this->isActive = $a; return $this; }

    public function getLocale(): string { return $this->locale; }
    public function setLocale(string $l): static { $this->locale = $l; return $this; }

    public function getResetToken(): ?string { return $this->resetToken; }
    public function setResetToken(?string $t): static { $this->resetToken = $t; return $this; }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable { return $this->resetTokenExpiresAt; }
    public function setResetTokenExpiresAt(?\DateTimeImmutable $d): static { $this->resetTokenExpiresAt = $d; return $this; }

    public function eraseCredentials(): void
    {
    }
}
