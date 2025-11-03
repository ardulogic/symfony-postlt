<?php
declare(strict_types=1);

namespace App\Orders\Entity;

use App\Orders\Enum\OrderLineStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'order_lines')]
#[ORM\UniqueConstraint(name: 'uniq_orderline_number_sku', columns: ['order_id', 'product_sku'])]
class OrderLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue('CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(length: 64)]
    private string $productSku;

    #[ORM\Column(type: 'integer')]
    private int $qtyOrdered;

    #[ORM\Column(type: 'integer')]
    private int $qtyReserved = 0;

    #[ORM\Column(type: 'integer')]
    private int $qtyShipped = 0;

    #[ORM\Column(length: 32)]
    private string $status = OrderLineStatus::PENDING->value;

    public function __construct(Order $order, string $sku, int $qty)
    {
        $this->order = $order;
        $this->productSku = $sku;
        $this->qtyOrdered = $qty;

        $this->recomputeStatus();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getProductSku(): string
    {
        return $this->productSku;
    }

    public function getQtyOrdered(): int
    {
        return $this->qtyOrdered;
    }

    public function getQtyReserved(): int
    {
        return $this->qtyReserved;
    }

    public function getQtyShipped(): int
    {
        return $this->qtyShipped;
    }

    public function getStatus(): OrderLineStatus
    {
        return OrderLineStatus::from($this->status);
    }

    public function setReservedDelta(int $delta): void
    {
        if ($delta <= 0) throw new \InvalidArgumentException('reserveDelta must be positive');
        $this->qtyReserved += $delta;
        if ($this->qtyReserved > $this->qtyOrdered) throw new \DomainException('qtyReserved > qtyOrdered');

        $this->recomputeStatus();
    }

    public function shipFromReserved(int $qty): void
    {
        if ($qty <= 0 || $qty > $this->qtyReserved) throw new \DomainException('Invalid ship qty');
        $this->qtyReserved -= $qty;
        $this->qtyShipped += $qty;
        if ($this->qtyShipped > $this->qtyOrdered) throw new \DomainException('qtyShipped > qtyOrdered');

        $this->recomputeStatus();
    }

    public function cancel(): void
    {
        $this->qtyReserved = 0;

        $this->status = OrderLineStatus::CANCELED->value;
    }

    /**
     * Direct setter for qtyReserved from warehousing (no status recomputation)
     */
    public function setQtyReserved(int $qty): void
    {
        if ($qty < 0) throw new \InvalidArgumentException('qtyReserved must be zero or positive');
        $this->qtyReserved = $qty;
    }

    /**
     * Direct setter for qtyShipped from warehousing (no status recomputation)
     */
    public function setQtyShipped(int $qty): void
    {
        if ($qty < 0) throw new \InvalidArgumentException('qtyShipped must be zero or positive');
        $this->qtyShipped = $qty;
    }

    /**
     * Direct setter for status from warehousing (no recomputation)
     */
    public function setStatus(OrderLineStatus $status): void
    {
        $this->status = $status->value;
    }

    private function recomputeStatus(): void
    {
        if ($this->status === OrderLineStatus::CANCELED->value) return;

        if ($this->qtyShipped >= $this->qtyOrdered) {
            $this->status = OrderLineStatus::SHIPPED->value;
            return;
        }
        // TODO: We can make another status PARTIALLY_SHIPPED, FULLY RESERVED
        if ($this->qtyReserved + $this->qtyShipped >= $this->qtyOrdered) {
            $this->status = OrderLineStatus::RESERVED->value;
            return;
        }
        if ($this->qtyReserved < $this->qtyOrdered - $this->qtyShipped) {
            $this->status = OrderLineStatus::RESERVED_PARTIAL->value;
            return;
        }

        $this->status = OrderLineStatus::PENDING->value;
    }

}
