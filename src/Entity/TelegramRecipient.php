<?php

namespace App\Entity;

use App\Repository\TelegramRecipientRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TelegramRecipientRepository::class)]
#[ORM\Table(name: 'telegram_recipients')]
class TelegramRecipient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column(length: 50)]
    private string $chatId;

    #[ORM\Column]
    private bool $isActive = true;

    public function getId(): ?int { return $this->id; }

    public function getLabel(): string { return $this->label; }
    public function setLabel(string $l): static { $this->label = $l; return $this; }

    public function getChatId(): string { return $this->chatId; }
    public function setChatId(string $c): static { $this->chatId = $c; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $a): static { $this->isActive = $a; return $this; }
}
