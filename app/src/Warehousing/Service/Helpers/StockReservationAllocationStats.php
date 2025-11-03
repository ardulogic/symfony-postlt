<?php

namespace App\Warehousing\Service\Helpers;


final class StockReservationAllocationStats
{
    public function __construct(
        public readonly int $distinctWarehouses,
        public readonly int $reservedUnits,
        public readonly int $orderedUnits,
        public readonly int $shippedUnits,
    ) {
        foreach ([
                     'distinctWarehouses' => $this->distinctWarehouses,
                     'reservedUnits'      => $this->reservedUnits,
                     'orderedUnits'       => $this->orderedUnits,
                     'shippedUnits'       => $this->shippedUnits,
                 ] as $name => $val) {
            if ($val < 0) {
                throw new \InvalidArgumentException("$name must be >= 0");
            }
        }
    }

    public function missingUnits(): int
    {
        return max(0, $this->orderedUnits - $this->reservedUnits);
    }

    /** 1.0 = fully reserved; 0.0 = nothing reserved. */
    public function fillRate(): float
    {
        return $this->orderedUnits === 0 ? 1.0 : $this->reservedUnits / $this->orderedUnits;
    }

    public function isComplete(): bool
    {
        return $this->missingUnits() === 0;
    }

    /** Handy if you want to return an array from your controller/service. */
    public function toArray(): array
    {
        return [
            'distinct_warehouses' => $this->distinctWarehouses,
            'reserved_units'      => $this->reservedUnits,
            'ordered_units'       => $this->orderedUnits,
            'shipped_units'       => $this->shippedUnits,
            'missing_units'       => $this->missingUnits(),
            'fill_rate'           => $this->fillRate(),
            'complete'            => $this->isComplete(),
        ];
    }

}
