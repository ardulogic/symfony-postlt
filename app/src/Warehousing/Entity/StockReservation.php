<?php
declare(strict_types=1);

namespace App\Warehousing\Entity;

use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Enum\StockReservationStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_reservations')]
class StockReservation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue('CUSTOM')]
    #[ORM\CustomIdGenerator('doctrine.uuid_generator')]
    private string $id;

    #[ORM\Column(length: 32, unique: true)]
    public string $number; // external order number

    #[ORM\Column(length: 32)]
    private string $status = StockReservationLineStatus::PENDING->value;

    #[ORM\OneToMany(mappedBy: 'stockReservation', targetEntity: StockReservationLine::class, cascade: ['persist'], orphanRemoval: true)]
    private iterable $lines;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $reallocationLocked = false;

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

    /** @return Collection<int, StockReservationLine> */
    public function getLines(): Collection
    {
        // ensure it’s a Collection for the serializer
        return $this->lines instanceof Collection ? $this->lines : new ArrayCollection($this->lines ?? []);
    }

    public function setLines(iterable $lines): void
    {
        $this->lines = $lines;
    }

    public function isReallocationLocked(): bool
    {
        return $this->reallocationLocked;
    }

    public function setReallocationLocked(bool $locked): void
    {
        $this->reallocationLocked = $locked;
    }

    public function recomputeStatus(): void
    {
        $pending = 0;
        $cancelled = 0;
        $shipped = 0;
        $reserved = 0;
        $reservedPartial = 0;
        $outOfStock = 0;
        $totalItems = $this->getLines()->count();

        foreach ($this->getLines() as $line) {
            switch ($line->getStatus()) {
                case StockReservationLineStatus::CANCELED:
                    $cancelled++;
                    break;
                case StockReservationLineStatus::SHIPPED:
                    $shipped++;
                    break;
                case StockReservationLineStatus::RESERVED:
                    $reserved++;
                    break;
                case StockReservationLineStatus::RESERVED_PARTIAL:
                    $reservedPartial++;
                    break;
                case StockReservationLineStatus::OUT_OF_STOCK:
                    $outOfStock++;
                    break;
                case StockReservationLineStatus::PENDING:
                    $pending++;
                    break;
            }
        }

        if ($pending > 0) {
            $this->status =             StockReservationStatus::PENDING->value;
            return;
        }

        if ($totalItems === $cancelled) {
            $this->status =             StockReservationStatus::CANCELED->value;
            return;
        }

        if ($totalItems === $shipped) {
            $this->status =             StockReservationStatus::SHIPPED->value;
            return;
        }

        if ($totalItems === $reserved) {
            $this->status =             StockReservationStatus::RESERVED->value;

            // Lock reallocation if fully reserved and not fractured across warehouses
            $this->setReallocationLocked($this->isFullyReservedInSingleWarehouse());
            return;
        }

        if ($totalItems === $outOfStock) {
            $this->status =             StockReservationStatus::OUT_OF_STOCK->value;
            return;
        }

        if ($reservedPartial > 0) {
            $this->status =             StockReservationStatus::RESERVED_PARTIAL->value;
            $this->setReallocationLocked(false);
            return;
        }

    }

    private function isFullyReservedInSingleWarehouse(): bool
    {
        $warehouseId = null;
        foreach ($this->getLines() as $line) {
            // must be fully reserved and assigned to the same warehouse
            if ($line->getStatus() !== StockReservationLineStatus::RESERVED) {
                return false;
            }
            $currentWh = $line->getWarehouse();
            if ($currentWh === null) {
                return false;
            }
            $currentId = method_exists($currentWh, 'getId') ? $currentWh->getId() : spl_object_hash($currentWh);
            if ($warehouseId === null) {
                $warehouseId = $currentId;
            } elseif ($warehouseId !== $currentId) {
                return false;
            }
        }

        return true;
    }



}
