<?php

namespace App\Entity;

use App\Repository\DisplayConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DisplayConfigRepository::class)]
#[ORM\Table(name: 'display_config')]
class DisplayConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column] private ?int $id = null;

    // JSON List des codes à montrer
    #[ORM\Column(type: 'json')]
    private array $codes = [];

    // eur en local ou local en euro
    #[ORM\Column(length: 16)]
    private string $direction = 'eur_to_local';

    public function getId(): ?int { return $this->id; }

    public function getCodes(): array { return $this->codes; }
    public function setCodes(array $c): self
    {
        $this->codes = array_values(array_unique($c));
        return $this;
    }

    public function getDirection(): string { return $this->direction; }
    public function setDirection(string $d): self
    {
        $this->direction = in_array($d, ['eur_to_local','local_to_eur'], true) ? $d : 'eur_to_local';
        return $this;
    }
}
