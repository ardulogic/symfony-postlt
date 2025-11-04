<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Helpers;

use App\Warehousing\Dto\StockReceiveDto;
use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Service\StockItemService;
use Doctrine\Persistence\ObjectManager;

/**
 * Builder for creating clear, readable stock allocation test scenarios.
 * Makes it obvious what stock exists where when reading tests.
 */
final class StockAllocationScenarioBuilder
{
    private array $stockSetup = [];
    private ObjectManager $om;
    private StockItemService $stockItemService;

    public function __construct(ObjectManager $om, StockItemService $stockItemService)
    {
        $this->om = $om;
        $this->stockItemService = $stockItemService;
    }

    /**
     * Add stock to a warehouse for a SKU.
     * Example: ->addStock('WARE-EU-1', 'SKU-001', 10)
     */
    public function addStock(string $warehouseCode, string $sku, int $quantity): self
    {
        $this->stockSetup[] = [$warehouseCode, $sku, $quantity];
        return $this;
    }

    /**
     * Convenience: Add stock for multiple warehouses for the same SKU.
     * Example: ->addStockForSku('SKU-001', ['WARE-EU-1' => 10, 'WARE-EU-2' => 5])
     */
    public function addStockForSku(string $sku, array $warehouseQuantities): self
    {
        foreach ($warehouseQuantities as $warehouseCode => $quantity) {
            $this->addStock($warehouseCode, $sku, $quantity);
        }
        return $this;
    }

    /**
     * Build the scenario and persist all stock items.
     */
    public function build(): void
    {
        $whRepo = $this->om->getRepository(Warehouse::class);

        foreach ($this->stockSetup as [$warehouseCode, $sku, $quantity]) {
            /** @var Warehouse|null $warehouse */
            $warehouse = $whRepo->findOneBy(['code' => $warehouseCode]);
            if ($warehouse === null) {
                throw new \RuntimeException(
                    sprintf('Warehouse "%s" not found. Ensure warehouse fixture runs first.', $warehouseCode)
                );
            }

            if ($quantity > 0) {
                $dto = StockReceiveDto::fromArray(['qty' => $quantity]);
                $this->stockItemService->receive($warehouse, $sku, $dto);
            }
        }

        $this->om->flush();
    }

    /**
     * Reset the builder (useful if reusing in same test).
     */
    public function reset(): self
    {
        $this->stockSetup = [];
        return $this;
    }
}

