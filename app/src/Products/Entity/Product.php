<?php
namespace App\Products\Entity;

use App\Products\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Table(name: 'products')]
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[UniqueEntity(fields: ['sku'], message: 'SKU must be unique.', groups: ['create', 'update'])]
class Product
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    #[Groups(['product:list', 'product:read'])]
    private ?int $id = null;
    #[ORM\Column(length: 64, unique: true)]
    #[Groups(['product:list', 'product:read'])]
    private string $sku;
    #[ORM\Column(length: 160)]
    #[Groups(['product:list', 'product:read'])] // Used for serializing object
    private string $name;

    public function getId(): ?int { return $this->id; }
    public function getSku(): string { return $this->sku; }
    public function setSku(string $sku): void { $this->sku = $sku; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; }

}
