<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(columns: ['email'], name: 'idx_user_email')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    #[ORM\Column(length: 80)] private string $firstName;

    #[ORM\Column(length: 120)] private string $name;

    #[ORM\Column(length: 180, unique: true)] private string $email;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateOfBirth;

    #[ORM\Column(length: 255)] private string $passwordHash;

    #[ORM\Column(length: 32)] private string $role = 'user';

    #[ORM\Column] private \DateTimeImmutable $createdAt;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): self { $this->firstName = $v; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $v): self { $this->name = $v; return $this; }

    public function getEmail(): string { return $this->email; }
    public function setEmail(string $v): self { $this->email = $v; return $this; }

    public function getDateOfBirth(): \DateTimeImmutable { return $this->dateOfBirth; }
    public function setDateOfBirth(\DateTimeImmutable $d): self { $this->dateOfBirth = $d; return $this; }

    public function getPasswordHash(): string { return $this->passwordHash; }
    public function setPasswordHash(string $v): self { $this->passwordHash = $v; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $v): self { $this->role = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

