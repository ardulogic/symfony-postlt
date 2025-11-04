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
        // Prioritize bigger orders first (helps consolidation)
        $this->sortSkuLinesByOrderedQtyDesc($unassignedSkuLines);

        // Array of allocated warehouses
        $allocatedWhs = [];

        // Single query for all StockItems of SKUs in the reservation.
        // Stock items are picked in desc availability, so biggest line orders could be fulfilled first
        $stockItems = $this->stockRepo->getBySkusSortedByDescAvailability(array_keys($unassignedSkuLines));

        // While there are SKUs still unassigned, pick the best warehouse that fully covers
        // the most SKUs in one shot (no split per SKU in this phase).
        while (!empty($unassignedSkuLines)) {
            $pick = $this->pickBestWarehouseForBatch($stockItems, $unassignedSkuLines);

            if (empty($pick)) {
                // No warehouse can fully cover any remaining SKU → stop; we'll handle partial-only SKUs later.
                break;
            }

            $allocatedWhs[] = $pick;
            foreach ($pick['skus'] ?? [] as $sku) {
                unset($unassignedSkuLines[$sku]);
            }
        }

        // Second pass: For any remaining SKUs (partial-only scenarios), assign each SKU
        // to a single best warehouse (prefer already chosen warehouses to minimize count).
        if (!empty($unassignedSkuLines)) {
            // Build a quick index of existing allocations by warehouse code for augmentation.
            $allocByCode = [];
            foreach ($allocatedWhs as $idx => $bundle) {
                $code = $bundle['warehouse']->getCode();
                $allocByCode[$code] = $idx;
            }

            foreach ($unassignedSkuLines as $sku => $line) {
                // Gather candidate StockItems for this SKU
                $candidates = [];
                foreach ($stockItems as $si) {
                    if ($si->getProductSku() === $sku && $si->getAvailableQty() > 0) {
                        $candidates[] = $si;
                    }
                }

                if (!$candidates) {
                    // Leave unassigned; will be marked OUT_OF_STOCK below
                    continue;
                }

                // Prefer already-chosen warehouses
                $preferred = array_filter(
                    $candidates,
                    static function (StockItem $si) use ($allocByCode): bool {
                        return array_key_exists($si->getWarehouse()->getCode(), $allocByCode);
                    }
                );

                $pool = $preferred ?: $candidates;

                // Pick the candidate with the highest available qty
                usort($pool, static function (StockItem $a, StockItem $b): int {
                    return $b->getAvailableQty() <=> $a->getAvailableQty();
                });

                $best = $pool[0];
                $wCode = $best->getWarehouse()->getCode();

                if (isset($allocByCode[$wCode])) {
                    $idx = $allocByCode[$wCode];
                    $allocatedWhs[$idx]['skus'][] = $sku;
                    $allocatedWhs[$idx]['items'][$sku] = $best;
                } else {
                    $allocatedWhs[] = [
                        'skus'      => [$sku],
                        'items'     => [$sku => $best],
                        'warehouse' => $best->getWarehouse(),
                    ];
                    $allocByCode[$wCode] = array_key_last($allocatedWhs);
                }
            }
        }

        foreach ($reservation->getLines() as $reservationLine) {
            $isAssigned = false;

            foreach ($allocatedWhs as $allocatedWh) {
                $newWarehouse = $allocatedWh['warehouse'];
                $stockItem = $allocatedWh['items'][$reservationLine->getProductSku()] ?? false;

                // Does not exist in the warehouse
                if (!$stockItem) {
                    continue;
                }

                /** @var $stockItem StockItem */
                if ($reservationLine->getStockItem()) {
                    $reservationLine->releaseFromStock();
                }

                $reservationLine->setStockItem($stockItem);
                $reservationLine->reserveOnStock();

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
            if ($this->stockLineCanBeCancelled($line)) {
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
     *   - Build a per-warehouse map of SKUs that are FULLY coverable (avail >= ordered).
     *   - Track:
     *       * skus[]        → list of SKUs this warehouse can contribute to (even partially)
     *       * items[sku]    → the StockItem object for that SKU in this warehouse
     *       * warehouse     → the Warehouse entity (for convenience)
     *       * item_count    → how many SKUs this warehouse touches (used for ranking)
     *       * lowest_stock  → the minimum available quantity across the SKUs it touches
     *   - Sort warehouses by:
     *       1) item_count DESC  (covers the most SKUs fully)
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

            if ($line && $availQty >= $line->getOrderedQty()) {
                // Only consider full coverage in this phase
                $wareSkuMap[$wCode]['skus'][] = $sku;
                $wareSkuMap[$wCode]['items'][$sku] = $stockItem;
                $wareSkuMap[$wCode]['warehouse'] = $stockItem->getWarehouse();
                $wareSkuMap[$wCode]['item_count'] = ($wareSkuMap[$wCode]['item_count'] ?? 0) + 1;

                // Track the minimum available qty among its fully covered SKUs (headroom proxy)
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

        uasort($wareSkuMap, function (array $a, array $b): int {
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
            [StockReservationStatus::RESERVED_PARTIAL, StockReservationStatus::RESERVED],
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
            $shippedItems += $line->getOrderedQty();

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
