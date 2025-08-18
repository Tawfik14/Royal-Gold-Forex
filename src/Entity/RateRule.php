<?php

namespace App\Entity;

use App\Repository\RateRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RateRuleRepository::class)]
#[ORM\Table(name: 'rate_rules')]
#[ORM\UniqueConstraint(name: 'uniq_rate_rule_code', columns: ['code'])]
class RateRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $code;

    // 'none' | 'manual' | 'percent'
    #[ORM\Column(length: 16)]
    private string $mode = 'none';

    // Manual mode: explicit buy/sell
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $manualBuy = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $manualSell = null;

    // Percent mode: relative to spot mid
    // buy = mid * (1 - percentBuy/100), sell = mid * (1 + percentSell/100)
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $percentBuy = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $percentSell = null;

    #[ORM\Column] private \DateTimeImmutable $updatedAt;

    public function __construct() { $this->updatedAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $v): self { $this->code = $v; return $this; }

    public function getMode(): string { return $this->mode; }
    public function setMode(string $v): self { $this->mode = $v; return $this; }

    public function getManualBuy(): ?float { return $this->manualBuy; }
    public function setManualBuy(?float $v): self { $this->manualBuy = $v; return $this; }

    public function getManualSell(): ?float { return $this->manualSell; }
    public function setManualSell(?float $v): self { $this->manualSell = $v; return $this; }

    public function getPercentBuy(): ?float { return $this->percentBuy; }
    public function setPercentBuy(?float $v): self { $this->percentBuy = $v; return $this; }

    public function getPercentSell(): ?float { return $this->percentSell; }
    public function setPercentSell(?float $v): self { $this->percentSell = $v; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
