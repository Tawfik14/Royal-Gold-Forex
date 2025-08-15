<?php

namespace App\Entity;

use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
#[ORM\Table(name: 'invoices')]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $firstName;

    #[ORM\Column(length: 120)]
    private string $lastName;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateOfBirth;

    #[ORM\Column(length: 180)]
    private string $address;

    #[ORM\Column(length: 20, unique: true)]
    private string $invoiceCode;

    // 'cash' | 'card' | 'transfer'
    #[ORM\Column(length: 16)]
    private string $paymentMethod;

    #[ORM\Column] private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): self { $this->firstName = $v; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): self { $this->lastName = $v; return $this; }
    public function getDateOfBirth(): \DateTimeImmutable { return $this->dateOfBirth; }
    public function setDateOfBirth(\DateTimeImmutable $d): self { $this->dateOfBirth = $d; return $this; }
    public function getAddress(): string { return $this->address; }
    public function setAddress(string $v): self { $this->address = $v; return $this; }
    public function getInvoiceCode(): string { return $this->invoiceCode; }
    public function setInvoiceCode(string $v): self { $this->invoiceCode = $v; return $this; }
    public function getPaymentMethod(): string { return $this->paymentMethod; }
    public function setPaymentMethod(string $v): self { $this->paymentMethod = $v; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** @return Collection<int, InvoiceItem> */ public function getItems(): Collection { return $this->items; }
    public function addItem(InvoiceItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setInvoice($this);
        }
        return $this;
    }
}

