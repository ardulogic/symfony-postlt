<?php
declare(strict_types=1);

namespace App\Warehousing\Entity;

use App\Warehousing\Enum\StockReservationLineStatus;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_reservation_lines')]
#[ORM\UniqueConstraint(name: 'uniq_line_res_wh_sku', columns: ['stock_reservation_id', 'warehouse_id', 'product_sku'])]
class StockReservationLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue('CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: StockReservation::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StockReservation $stockReservation;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Warehouse $warehouse = null;

    #[ORM\ManyToOne(targetEntity: StockItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?StockItem $stockItem = null;

    #[ORM\Column(length: 64)]
    private string $productSku;

    #[ORM\Column(type: 'integer')]
    private int $qtyOrdered;

    #[ORM\Column(type: 'integer')]
    private int $qtyReserved = 0;

    #[ORM\Column(type: 'integer')]
    private int $qtyShipped = 0;

    #[ORM\Column(length: 32)]
    private string $status = StockReservationLineStatus::PENDING->value;

    public function __construct(StockReservation $reservation, string $sku, int $qty)
    {
        $this->stockReservation = $reservation;
        $this->productSku = $sku;
        $this->qtyOrdered = $qty;

        $this->recomputeStatus();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getWarehouse(): ?Warehouse
    {
        return $this->warehouse;
    }

    public function getStockItem(): ?StockItem
    {
        return $this->stockItem;
    }

    public function setStockItem(?StockItem $stockItem): void
    {
        $this->stockItem = $stockItem;
    }

    public function setWarehouse(?Warehouse $warehouse): void
    {
        $this->warehouse = $warehouse;
    }

    public function getProductSku(): string
    {
        return $this->productSku;
    }

    public function getOrderedQty(): int
    {
        return $this->qtyOrdered;
    }

    public function getReservedQty(): int
    {
        return $this->qtyReserved;
    }

    public function getUnreservedQty(): int
    {
        return $this->qtyOrdered - $this->qtyReserved;
    }


    public function getShippedQty(): int
    {
        return $this->qtyShipped;
    }

    public function getStatus(): StockReservationLineStatus
    {
        return StockReservationLineStatus::from($this->status);
    }

    public function releaseReservedQty(): void
    {
        $this->setReservedQty(0);
    }

    public function setReservedQty(int $qty): void
    {
        if ($qty < 0) throw new \InvalidArgumentException('Reserved must be zero or positive');
        $this->qtyReserved = $qty;

        if ($this->qtyReserved > $this->qtyOrdered) throw new \DomainException('qtyReserved > qtyOrdered');

        $this->recomputeStatus();
    }

    public function setReservedQtyAt(int $qty, Warehouse $warehouse): void
    {
        $this->setWarehouse($warehouse);
        $this->setReservedQty($qty);

        $this->recomputeStatus();
    }

    public function setShippedQty(int $qty): void
    {
        $this->qtyShipped = $qty;

        $this->recomputeStatus();
    }

    public function setAsOutOfStock(): void
    {
        $this->qtyReserved = 0;

        $this->status = StockReservationLineStatus::OUT_OF_STOCK->value;
    }

    public function cancel(): void
    {
        $this->qtyReserved = 0;

        $this->status = StockReservationLineStatus::CANCELED->value;
    }

    private function recomputeStatus(): void
    {
        if ($this->status === StockReservationLineStatus::CANCELED->value) return;

        if ($this->qtyShipped >= $this->qtyOrdered) {
            $this->status = StockReservationLineStatus::SHIPPED->value;
            return;
        }

        if ($this->qtyShipped > 0) {
            $this->status = StockReservationLineStatus::SHIPPED_PARTIAL->value;
            return;
        }

        if ($this->qtyReserved >= $this->qtyOrdered) {
            $this->status = StockReservationLineStatus::RESERVED->value;
            return;
        }

        if ($this->qtyReserved > 0 && $this->qtyReserved < $this->qtyOrdered) {
            $this->status = StockReservationLineStatus::RESERVED_PARTIAL->value;
            return;
        }

        $this->status = StockReservationLineStatus::PENDING->value;
    }

    /**
     *
     * @return void
     */
    public function reserveOnStock(): void
    {
        if ($this->getStockItem()) {
            $qty = min($this->getStockItem()->getAvailableQty(), $this->getOrderedQty());

            $this->getStockItem()->reserve($qty);
            $this->setReservedQtyAt($qty, $this->getStockItem()->getWarehouse());

            $this->recomputeStatus();
        } else {
            throw new \DomainException("You must set stock item first before calling this method.");
        }
    }

    public function releaseFromStock(): void
    {
        if ($this->getStockItem()) {
            $this->getStockItem()->release($this->getReservedQty());
            $this->setWarehouse(null);
            $this->setStockItem(null);
            $this->releaseReservedQty();

            $this->recomputeStatus();
        } else {
            throw new \DomainException("You must set stock item first before calling this method.");
        }
    }

    /**
     * Works with both partial/non-partial shipments
     * @return void
     */
    public function shipStock(): void
    {
        if ($this->getStockItem()) {
            $reservedQty = $this->getReservedQty();

            $this->setShippedQty($reservedQty);
            $this->releaseReservedQty();
            $this->getStockItem()->consumeReserved($reservedQty);

            $this->recomputeStatus();
        } else {
            throw new \DomainException("You must set stock item first before calling this method.");
        }
    }

}
