<?php

namespace App\Warehousing\Service\Helpers;

final class StockReallocationResult
{

    private int $processed = 0;
    private array $results = [];

    public function processed(): int
    {
        return $this->processed;
    }

    public function toArray(): array
    {
        return $this->results;
    }

    public function addAllocationStats(string $number,
                                       StockReservationAllocationStats $preAllocationStats,
                                       StockReservationAllocationStats $postAllocationStats): void
    {
        $this->results[$number] = ['pre' => $preAllocationStats->toArray(), 'post' => $postAllocationStats->toArray()];
        $this->processed++;
    }
}
