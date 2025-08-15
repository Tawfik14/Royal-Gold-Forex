<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservations')]
#[ORM\Index(columns: ['created_at'], name: 'idx_res_created')]
class Reservation
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 80)]
    private string $firstName;

    #[ORM\Column(length: 120)]
    private string $lastName;

    // 'buy' (client achète devise, paie EUR) | 'sell' (client vend devise, reçoit EUR)
    #[ORM\Column(length: 8)]
    private string $operation;

    // code commande ~10 alnum
    #[ORM\Column(length: 20, unique: true)]
    private string $orderCode;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    // échéance: aujourd'hui 19:00
    #[ORM\Column(name: 'pickup_deadline')]
    private \DateTimeImmutable $pickupDeadline;

    #[ORM\OneToMany(mappedBy: 'reservation', targetEntity: ReservationItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function setUser(User $u): self { $this->user = $u; return $this; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): self { $this->firstName = $v; return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): self { $this->lastName = $v; return $this; }

    public function getOperation(): string { return $this->operation; }
    public function setOperation(string $v): self { $this->operation = $v; return $this; }

    public function getOrderCode(): string { return $this->orderCode; }
    public function setOrderCode(string $v): self { $this->orderCode = $v; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $v): self { $this->status = $v; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getPickupDeadline(): \DateTimeImmutable { return $this->pickupDeadline; }
    public function setPickupDeadline(\DateTimeImmutable $d): self { $this->pickupDeadline = $d; return $this; }

    /** @return Collection<int, ReservationItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(ReservationItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setReservation($this);
        }
        return $this;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->status === self::STATUS_PENDING && $now > $this->pickupDeadline;
    }

    public function remainingSeconds(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        if ($this->isExpired($now)) return 0;
        return max(0, $this->pickupDeadline->getTimestamp() - $now->getTimestamp());
    }
}

