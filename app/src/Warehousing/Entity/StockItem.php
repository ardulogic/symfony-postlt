<?php
declare(strict_types=1);

namespace App\Warehousing\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_items')]
#[ORM\UniqueConstraint(name: 'uniq_stock_wh_sku', columns: ['warehouse_id', 'product_sku'])]
class StockItem
{
    // We're using uuid instead of regular id here
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue('CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    // Human/visible SKU as received from upstream (case as provided)
    #[ORM\Column(length: 64)]
    private string $productSku;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $onHandQty = 0;

    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private int $reservedQty = 0;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $lockVersion = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Warehouse $warehouse, string $sku)
    {
        $this->warehouse = $warehouse;
        $this->productSku = $sku;

        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getSku(): string
    {
        return $this->productSku;
    }

    public function setSku(string $sku): void
    {
        $this->productSku = $sku;
    }

    public function getWarehouse(): Warehouse
    {
        return $this->warehouse; // Doctrine may return a proxy; it behaves like Warehouse.
    }

    public function getOnHandQty(): int
    {
        return $this->onHandQty;
    }

    public function getReservedQty(): int
    {
        return $this->reservedQty;
    }

    public function available(): int
    {
        return $this->onHandQty - $this->reservedQty;
    }

    public function adjustOnHand(int $delta): void
    {
        $new = $this->onHandQty + $delta;
        if ($new < 0) throw new \DomainException('On-hand cannot go negative');
        if ($this->reservedQty > $new) throw new \DomainException('On-hand cannot drop below reserved');
        $this->onHandQty = $new;
        $this->touch();
    }

    public function reserve(int $qty): void
    {
        if ($qty <= 0) throw new \InvalidArgumentException('Reserve qty must be positive');
        if ($this->available() < $qty) throw new \DomainException('Insufficient available stock');
        $this->reservedQty += $qty;
        $this->touch();
    }

    public function release(int $qty): void
    {
        if ($qty <= 0 || $qty > $this->reservedQty) throw new \DomainException('Invalid release qty');
        $this->reservedQty -= $qty;
        $this->touch();
    }

    public function consumeReserved(int $qty): void
    {
        if ($qty <= 0 || $qty > $this->reservedQty) throw new \DomainException('Invalid consume qty');
        $this->reservedQty -= $qty;
        $this->onHandQty -= $qty;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }


}
