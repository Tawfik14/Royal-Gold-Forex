<?php

namespace App\Entity;

use App\Repository\InvoiceItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceItemRepository::class)]
#[ORM\Table(name: 'invoice_items')]
class InvoiceItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(length: 8)]
    private string $currency;

    
    #[ORM\Column(type: 'decimal', precision: 16, scale: 2, nullable: true)]
    private ?string $amountEuro = null;

    #[ORM\Column(type: 'decimal', precision: 16, scale: 2, nullable: true)]
    private ?string $amountLocal = null;

    
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rateEurToLocal = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $rateLocalToEur = null;

    public function getId(): ?int { return $this->id; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function setInvoice(Invoice $i): self { $this->invoice = $i; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): self { $this->currency = $c; return $this; }
    public function getAmountEuro(): ?string { return $this->amountEuro; }
    public function setAmountEuro(?string $v): self { $this->amountEuro = $v; return $this; }
    public function getAmountLocal(): ?string { return $this->amountLocal; }
    public function setAmountLocal(?string $v): self { $this->amountLocal = $v; return $this; }
    public function getRateEurToLocal(): ?float { return $this->rateEurToLocal; }
    public function setRateEurToLocal(?float $v): self { $this->rateEurToLocal = $v; return $this; }
    public function getRateLocalToEur(): ?float { return $this->rateLocalToEur; }
    public function setRateLocalToEur(?float $v): self { $this->rateLocalToEur = $v; return $this; }
}
