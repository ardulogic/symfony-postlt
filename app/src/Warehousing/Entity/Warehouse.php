<?php

namespace App\Warehousing\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'warehouses')]
class Warehouse
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['warehouse:list', 'warehouse:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 32, unique: true)]
    #[Groups(['warehouse:list', 'warehouse:read'])]
    private string $code;

    #[ORM\Column(length: 128)]
    #[Groups(['warehouse:list', 'warehouse:read'])]
    private string $name;

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function setCode(?string $code): void { $this->code = $code; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

}
