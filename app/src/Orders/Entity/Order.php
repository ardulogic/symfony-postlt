<?php
declare(strict_types=1);

namespace App\Orders\Entity;

use App\Orders\Enum\OrderLineStatus;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue('CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    private string $id;

    #[ORM\Column(length: 32, unique: true)]
    public string $number; // external order number

    #[ORM\Column(length: 32)]
    private string $status = OrderLineStatus::PENDING->value;

    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderLine::class, cascade: ['persist'], orphanRemoval: true)]
    private iterable $lines;

    public function getId(): ?string
    {
        return $this->id ?? null;
    }

    public function setNumber(string $number): void
    {
        $this->number = $number;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return Collection<int, OrderLine> */
    public function getLines(): Collection
    {
        // ensure it’s a Collection for the serializer
        return $this->lines instanceof Collection ? $this->lines : new ArrayCollection($this->lines ?? []);
    }

    public function setLines(iterable $lines): void
    {
        $this->lines = $lines;
    }

}
