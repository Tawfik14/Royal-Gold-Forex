<?php

namespace App\Entity;

use App\Repository\ReservationItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationItemRepository::class)]
#[ORM\Table(name: 'reservation_items')]
class ReservationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Reservation::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private Reservation $reservation;

    #[ORM\Column(length: 8)]
    private string $currency;

    // Montants saisis/calculés au moment de la commande (snapshot)
    #[ORM\Column(type: 'decimal', precision: 16, scale: 2, nullable: true)]
    private ?string $amountEuro = null;

    #[ORM\Column(type: 'decimal', precision: 16, scale: 2, nullable: true)]
    private ?string $amountLocal = null;

    // Taux appliqués (snapshot)
    #[ORM\Column(type: 'float')]
    private float $rateBuy;

    #[ORM\Column(type: 'float')]
    private float $rateSell;

    public function getId(): ?int { return $this->id; }

    public function getReservation(): Reservation { return $this->reservation; }
    public function setReservation(Reservation $r): self { $this->reservation = $r; return $this; }

    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): self { $this->currency = $c; return $this; }

    public function getAmountEuro(): ?string { return $this->amountEuro; }
    public function setAmountEuro(?string $v): self { $this->amountEuro = $v; return $this; }

    public function getAmountLocal(): ?string { return $this->amountLocal; }
    public function setAmountLocal(?string $v): self { $this->amountLocal = $v; return $this; }

    public function getRateBuy(): float { return $this->rateBuy; }
    public function setRateBuy(float $v): self { $this->rateBuy = $v; return $this; }

    public function getRateSell(): float { return $this->rateSell; }
    public function setRateSell(float $v): self { $this->rateSell = $v; return $this; }
}

