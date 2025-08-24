<?php

namespace App\Entity;

use App\Repository\RateOverrideRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RateOverrideRepository::class)]
#[ORM\Table(name: 'rate_overrides')]
#[ORM\UniqueConstraint(name: 'uniq_rate_code', columns: ['code'])]
class RateOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $code;

    #[ORM\Column(type: 'float')]
    private float $value;

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $c): self { $this->code = $c; return $this; }

    public function getValue(): float { return $this->value; }
    public function setValue(float $v): self { $this->value = $v; return $this; }
}
