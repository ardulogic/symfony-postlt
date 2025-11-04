<?php

namespace App\Warehousing\Service\Helpers;

use App\Warehousing\Entity\StockItem;
use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Entity\StockReservationLine;
use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\StockReservationRepository;

class StockAllocator
{

    public function __construct(
        private StockItemRepository        $stockRepo,
        private StockReservationRepository $resRepo)
    {
    }

    /**
     * Strategy:
     * - Pick the single warehouse that fully covers reservation and has the most SKUs in stock.
     * - Keep picking the next best warehouse for remaining items using same strategy
     */
    public function allocateStockToReservationLines(StockReservation $reservation): ?StockReservation
    {
        // Required quantities per SKU.
        $unassignedSkuLines = $this->extractReservationLinesBySku($reservation);
        $this->sortSkuLinesByOrderedQtyDesc($unassignedSkuLines);

        // Array of allocated warehouses
        $allocWarehouses = [];

        // Single query for all StockItems of SKUs in the reservation.
        // Stock items are picked in desc availability, so biggest line orders could be fulfilled first
        $stockItems = $this->stockRepo->getBySkusSortedByDescAvailability(array_keys($unassignedSkuLines));

        // While there are SKUs still unassigned, pick the best warehouse for a batch.
        while (!empty($unassignedSkuLines)) {
            $bestSingleWh = $this->pickBestWarehouseForBatch($stockItems, $unassignedSkuLines, $reservation);

            if (empty($bestSingleWh)) {
                // No warehouse can fully cover any remaining SKU → stop (leave the rest unassigned).
                break;
            }

            $allocWarehouses[] = $bestSingleWh;
            foreach ($bestSingleWh['skus'] ?? [] as $sku) {
                unset($unassignedSkuLines[$sku]);
            }
        }

        foreach ($reservation->getLines() as $reservationLine) {
            $isAssigned = false;

            foreach ($allocWarehouses as $allocWh) {
                /** @var $allocStockItem StockItem */
                $allocStockItem = $allocWh['items'][$reservationLine->getProductSku()] ?? false;

                // Does not exist in the warehouse
                if (!$allocStockItem) {
                    continue;
                }

                // Release stock if it warehouses changed
                if ($reservationLine->getReservedQty() > 0) {
                    $currentWhId = $reservationLine->getWarehouse()->getId();
                    $newWhId = $allocStockItem->getWarehouse()->getId();

                    $isAllocatedOnNewWarehouse = $currentWhId != $newWhId;

                    if ($isAllocatedOnNewWarehouse) {
                        $this->stockRepo->releaseAtomically(
                            $reservationLine->getWarehouse()->getId(),
                            $reservationLine->getProductSku(),
                            $reservationLine->getReservedQty(),
                        );
                    }
                }

                // Refresh stock item to get current database state before atomic reservation
                $this->stockRepo->getEntityManager()->refresh($allocStockItem);

                $reservationLine->setStockItem($allocStockItem);

                // Use atomic reservation to prevent race conditions
                $warehouseId = $allocStockItem->getWarehouse()->getId();
                $sku = $reservationLine->getProductSku();
                $orderedQty = $reservationLine->getOrderedQty();

                $reservedQty = $this->stockRepo->reserveAtomicallyUpTo($warehouseId, $sku, $orderedQty);

                if ($reservedQty > 0) {
                    $reservationLine->setReservedQtyAt($reservedQty, $allocStockItem->getWarehouse());

                    // Refresh the stock item entity to reflect the atomic change
                    $this->stockRepo->getEntityManager()->refresh($allocStockItem);
                } else {
                    $reservationLine->setAsOutOfStock();
                }

                $isAssigned = true;
                break;
            }

            // If no warehouses are assigned, product does not exist in stock at all
            if (!$isAssigned) {
                $reservationLine->setAsOutOfStock();
            }
        }

        $reservation->recomputeStatus();

        return $reservation;
    }

    /**
     * Sort reservation lines by remaining required quantity (DESC).
     * Works in-place, so no return.
     *
     * @param array<string, StockReservationLine> $resLinesBySku
     * @return void
     */
    private function sortSkuLinesByOrderedQtyDesc(array &$resLinesBySku): void
    {
        uasort($resLinesBySku, function (StockReservationLine $a, StockReservationLine $b): int {
            // DESC order
            return $b->getOrderedQty() <=> $a->getOrderedQty();
        });
    }

    public function cancel(StockReservation $reservation): StockReservation
    {
        /** @var StockReservationLine $line */
        foreach ($reservation->getLines() as $line) {
            if ($this->stockLineCanBeCancelled($line) && $line->getReservedQty() > 0) {
                $this->stockRepo->releaseAtomically(
                    $line->getWarehouse()->getId(),
                    $line->getProductSku(),
                    $line->getReservedQty()
                );

                $line->cancel();
            }
        }

        $reservation->recomputeStatus();

        return $reservation;
    }


    /**
     * Pick the "best" warehouse for the current batch of still-needed SKUs.
     *
     * Strategy:
     *   - Build a per-warehouse map of SKUs that have any available qty (>0).
     *   - Track:
     *       * skus[]        → list of SKUs this warehouse can contribute to (even partially)
     *       * items[sku]    → the StockItem object for that SKU in this warehouse
     *       * warehouse     → the Warehouse entity (for convenience)
     *       * item_count    → how many SKUs this warehouse touches (used for ranking)
     *       * lowest_stock  → the minimum available quantity across the SKUs it touches
     *   - Sort warehouses by:
     *       1) item_count DESC  (covers the most SKUs)
     *       2) lowest_stock DESC (more headroom among the covered SKUs)
     *   - Return the top-ranked warehouse "bundle" (or null if none can help).
     *
     * Notes:
     *   - Partial reservation allowed: we include any SKU with availQty > 0 (not only fully coverable).
     *
     * @param StockItem[] $stockItems Stock items for all relevant SKUs (across warehouses).
     * @param array $resLinesBySku Reservation lines grouped by sku
     * @return array|null             Best warehouse bundle or null if no contributions possible.
     */
    private function pickBestWarehouseForBatch(array $stockItems, array $resLinesBySku): ?array
    {
        $wareSkuMap = [];

        foreach ($stockItems as $stockItem) {
            $wCode = $stockItem->getWarehouse()->getCode();
            $sku = $stockItem->getProductSku();
            $availQty = $stockItem->getAvailableQty();

            /* @var $line StockReservationLine|null */
            $line = $resLinesBySku[$sku] ?? false;

            if ($line && $line->getReservedQty() > 0) {
                if ($line->getWarehouse()?->getCode() === $wCode) {
                    $availQty += $line->getReservedQty();
                }
            }

            if ($line && $availQty > 0) {
                $wareSkuMap[$wCode]['skus'][] = $sku;
                $wareSkuMap[$wCode]['items'][$sku] = $stockItem;
                $wareSkuMap[$wCode]['warehouse'] = $stockItem->getWarehouse();
                $wareSkuMap[$wCode]['item_count'] = ($wareSkuMap[$wCode]['item_count'] ?? 0) + 1;

                // Track if this SKU can be fully covered by this warehouse
                $requiredQty = $line->getOrderedQty() - $line->getReservedQty();
                $canFullyCover = $availQty >= $requiredQty;

                if (!isset($wareSkuMap[$wCode]['full_coverage_count'])) {
                    $wareSkuMap[$wCode]['full_coverage_count'] = 0;
                }
                if ($canFullyCover) {
                    $wareSkuMap[$wCode]['full_coverage_count']++;
                }

                if (isset($wareSkuMap[$wCode]['lowest_stock'])) {
                    $wareSkuMap[$wCode]['lowest_stock'] = min($wareSkuMap[$wCode]['lowest_stock'], $availQty);
                } else {
                    $wareSkuMap[$wCode]['lowest_stock'] = $availQty;
                }
            }
        }

        if (empty($wareSkuMap)) {
            return null;
        }

        // Prioritize warehouses that can fully cover at least one SKU
        // Then rank by: full_coverage_count DESC, item_count DESC, lowest_stock DESC
        uasort($wareSkuMap, function (array $a, array $b): int {
            $aHasFullCoverage = ($a['full_coverage_count'] ?? 0) > 0;
            $bHasFullCoverage = ($b['full_coverage_count'] ?? 0) > 0;

            // First priority: warehouses with full coverage beat those without
            if ($aHasFullCoverage !== $bHasFullCoverage) {
                return $bHasFullCoverage <=> $aHasFullCoverage;
            }

            // Second priority: among warehouses with/without full coverage, rank by full_coverage_count
            $fullCoverageDiff = ($b['full_coverage_count'] ?? 0) <=> ($a['full_coverage_count'] ?? 0);
            if ($fullCoverageDiff !== 0) {
                return $fullCoverageDiff;
            }

            // Third priority: item_count, then lowest_stock
            return [$b['item_count'], $b['lowest_stock']]
                <=> [$a['item_count'], $a['lowest_stock']];
        });

        $bestPick = reset($wareSkuMap);

        return $bestPick;
    }

    /**
     * @return array<string,StockReservationLine>
     */
    private function extractReservationLinesBySku(StockReservation $reservation): array
    {
        $skuQtyMap = [];
        /** @var StockReservationLine $line */
        foreach ($reservation->getLines() as $line) {
            if ($this->stockLineCanBeReallocated($line)) {
                $skuQtyMap[$line->getProductSku()] = $line;
            }
        }

        return $skuQtyMap;
    }

    public function reallocateSkus(array $skus, int $limit = 50): StockReallocationResult
    {
        $result = new StockReallocationResult();

        $skus = array_values(array_unique(array_filter($skus)));
        if (!$skus) {
            return $result;
        }

        $targets = $this->resRepo->findByStatusContainingSkus(
            $skus,
            [
                // StockReservationStatus::PENDING, Pending is handled by a separate job.
                StockReservationStatus::RESERVED_PARTIAL,
                StockReservationStatus::RESERVED,
                StockReservationStatus::OUT_OF_STOCK,
            ],
            limit: $limit
        );

        foreach ($targets as $reservation) {
            $preAllocationStats = $this->getReservationAllocationStats($reservation);

            $this->allocateStockToReservationLines($reservation);

            $postAllocationStats = $this->getReservationAllocationStats($reservation);

            $result->addAllocationStats($reservation->getNumber(), $preAllocationStats, $postAllocationStats);
        }

        return $result;
    }


    public function getReservationAllocationStats(StockReservation $reservation): StockReservationAllocationStats
    {
        $whCodes = [];
        $reservedItems = 0;
        $orderedItems = 0;
        $shippedItems = 0;

        foreach ($reservation->getLines() as $line) {
            $reservedItems += $line->getReservedQty();
            $orderedItems += $line->getOrderedQty();
            $shippedItems += $line->getShippedQty();

            // use a placeholder to count unassigned as its own group
            $code = $line->getWarehouse()?->getCode();
            if ($code) {
                $whCodes[$code] = true;
            }
        }

        return new StockReservationAllocationStats(count($whCodes), $reservedItems, $orderedItems, $shippedItems);
    }

    public function stockLineCanBeReallocated(StockReservationLine $reservationLine): bool
    {
        return in_array($reservationLine->getStatus(), [
            StockReservationLineStatus::PENDING,
            StockReservationLineStatus::RESERVED,
            StockReservationLineStatus::RESERVED_PARTIAL,
            StockReservationLineStatus::OUT_OF_STOCK,

        ]);
    }

    public function stockLineCanBeCancelled(StockReservationLine $reservation): bool
    {
        return in_array($reservation->getStatus(), [
            StockReservationLineStatus::PENDING,
            StockReservationLineStatus::RESERVED,
            StockReservationLineStatus::RESERVED_PARTIAL,
            StockReservationLineStatus::OUT_OF_STOCK,
        ]);
    }
}
