<?php

namespace App\Warehousing\Tests\Http\StockAllocation;

use App\Tests\Support\WebTestCase;
use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Service\StockItemService;
use App\Warehousing\Tests\DataFixtures\WarehouseTestFixture;
use App\Warehousing\Tests\Helpers\StockAllocationScenarioBuilder;
use App\Warehousing\Tests\Helpers\StockReservationTestHelpers;

final class StockAllocationScenarioTest extends WebTestCase
{
    use StockReservationTestHelpers;

    private StockAllocationScenarioBuilder $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRepositories();

        $stockItemService = $this->c->get(StockItemService::class);
        $this->scenario = new StockAllocationScenarioBuilder($this->em, $stockItemService);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            WarehouseTestFixture::class,
        ];
    }

    /**
     * SCENARIO: Both SKUs are fully available at WARE-EU-2.
     * EXPECTED: Should consolidate to single warehouse (WARE-EU-2) even though
     *           other warehouses have stock, because consolidation minimizes warehouse count.
     */
    public function test_prefers_single_warehouse_when_all_skus_fully_available_at_same_warehouse(): void
    {
        $this->expectSuccess();

        // Setup: Both SKUs fully available at WARE-EU-2, with distracting stock elsewhere
        $this->scenario
            ->addStock('WARE-EU-2', 'SKU-CONSOLIDATE-1', 10)  // Both here
            ->addStock('WARE-EU-2', 'SKU-CONSOLIDATE-2', 10)  // Both here
            ->addStock('WARE-EU-1', 'SKU-CONSOLIDATE-1', 10) // Distraction
            ->addStock('WARE-EU-3', 'SKU-CONSOLIDATE-2', 10) // Distraction
            ->build();

        // Order: Both SKUs need 3 units each
        $this->createReservationWithLines('ORD-CONSOLIDATE-001', [
            ['productSku' => 'SKU-CONSOLIDATE-1', 'qty' => 3],
            ['productSku' => 'SKU-CONSOLIDATE-2', 'qty' => 3],
        ]);

        $res = $this->readReservation('ORD-CONSOLIDATE-001');
        $map = $this->lineMap($res);

        // Assertions
        self::assertSame(StockReservationStatus::RESERVED->value, $res['status']);
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map['SKU-CONSOLIDATE-1']['status']);
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map['SKU-CONSOLIDATE-2']['status']);

        $wh1 = $map['SKU-CONSOLIDATE-1']['warehouse'];
        $wh2 = $map['SKU-CONSOLIDATE-2']['warehouse'];
        self::assertNotNull($wh1);
        self::assertSame($wh1, $wh2, 'Both SKUs should consolidate to same warehouse');
        self::assertSame('WARE-EU-2', $wh1, 'Should prefer WARE-EU-2 where both SKUs are available');
    }

    /**
     * SCENARIO: SKU-A only fully available at WARE-EU-1, SKU-B only fully available at WARE-EU-3.
     * EXPECTED: Must use 2 warehouses (no split per SKU), but should use minimum possible.
     */
    public function test_uses_minimum_warehouses_when_skus_require_different_warehouses(): void
    {
        $this->expectSuccess();

        // Setup: SKU-A only fully at WARE-EU-1, SKU-B only fully at WARE-EU-3
        $this->scenario
            ->addStock('WARE-EU-1', 'SKU-A', 7)  // Only fully here
            ->addStock('WARE-EU-2', 'SKU-A', 3)  // Partial only
            ->addStock('WARE-EU-3', 'SKU-B', 5)  // Only fully here
            ->addStock('WARE-EU-2', 'SKU-B', 2)  // Partial only
            ->build();

        $all = $this->stockRepo->list(1, 100);

        // Order: Both need 5 units (SKU-A can only get from WARE-EU-1, SKU-B only from WARE-EU-3)
        $this->createReservationWithLines('ORD-MIX-001', [
            ['productSku' => 'SKU-A', 'qty' => 5],
            ['productSku' => 'SKU-B', 'qty' => 5],
        ]);

        $res = $this->readReservation('ORD-MIX-001');
        $map = $this->lineMap($res);

        // Assertions
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map['SKU-A']['status']);
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map['SKU-B']['status']);

        $whA = $map['SKU-A']['warehouse'];
        $whB = $map['SKU-B']['warehouse'];
        self::assertNotSame($whA, $whB, 'Different warehouses required when SKUs have no overlap');
        self::assertSame('WARE-EU-1', $whA, 'SKU-A must come from WARE-EU-1 (only full coverage)');
        self::assertSame('WARE-EU-3', $whB, 'SKU-B must come from WARE-EU-3 (only full coverage)');
    }

    /**
     * SCENARIO: SKU only has partial availability across all warehouses (total 3 units, need 5).
     * EXPECTED: Should assign to single warehouse with best availability (no split), partial reservation.
     */
    public function test_assigns_partial_sku_to_single_best_warehouse_without_split(): void
    {
        $this->expectSuccess();

        // Setup: SKU only partially available (total 3 units, but need 5)
        $this->scenario
            ->addStock('WARE-EU-1', 'SKU-PARTIAL', 1)  // Best partial
            ->addStock('WARE-EU-3', 'SKU-PARTIAL', 2)  // Smaller partial
            ->build();

        // Order: Need 5 units, but only 3 total available
        $this->createReservationWithLines('ORD-PARTIAL-001', [
            ['productSku' => 'SKU-PARTIAL', 'qty' => 5],
        ]);

        $res = $this->readReservation('ORD-PARTIAL-001');
        $map = $this->lineMap($res);

        // Assertions
        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $res['status']);
        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map['SKU-PARTIAL']['status']);
        self::assertNotNull($map['SKU-PARTIAL']['warehouse']);
        self::assertSame(2, $map['SKU-PARTIAL']['reserved'], 'Should reserve 2 (best available)');
        self::assertSame('WARE-EU-3', $map['SKU-PARTIAL']['warehouse'], 'Should pick best warehouse');
    }

    /**
     * SCENARIO: Two warehouses can fully cover both SKUs equally.
     * EXPECTED: Should consolidate to single warehouse (deterministic tie-break).
     */
    public function test_tie_break_consolidates_to_single_warehouse_when_multiple_can_cover(): void
    {
        $this->expectSuccess();

        // Setup: Both WARE-EU-1 and WARE-EU-2 can fully cover both SKUs
        $this->scenario
            ->addStock('WARE-EU-1', 'SKU-TIE-1', 5)
            ->addStock('WARE-EU-1', 'SKU-TIE-2', 5)
            ->addStock('WARE-EU-2', 'SKU-TIE-1', 5)
            ->addStock('WARE-EU-2', 'SKU-TIE-2', 5)
            ->build();

        // Order: Both need 2 units (both warehouses can fulfill)
        $this->createReservationWithLines('ORD-TIE-001', [
            ['productSku' => 'SKU-TIE-1', 'qty' => 2],
            ['productSku' => 'SKU-TIE-2', 'qty' => 2],
        ]);

        $res = $this->readReservation('ORD-TIE-001');
        $map = $this->lineMap($res);

        // Assertions
        $wh1 = $map['SKU-TIE-1']['warehouse'];
        $wh2 = $map['SKU-TIE-2']['warehouse'];
        self::assertNotNull($wh1);
        self::assertSame($wh1, $wh2, 'Should consolidate to single warehouse despite tie');
        // Note: Which warehouse is chosen depends on tie-break logic, but should be consistent
    }

    /**
     * SCENARIO: One warehouse can fully cover all SKUs, another can only partially cover some.
     * EXPECTED: Should prefer the warehouse that fully covers all SKUs.
     */
    public function test_prefers_warehouse_that_fully_covers_all_skus_over_partial_coverage(): void
    {
        $this->expectSuccess();

        // Setup: WARE-EU-2 can fully cover both SKUs, WARE-EU-1 can only partially
        $this->scenario
            ->addStock('WARE-EU-2', 'SKU-FULL-PREF-1', 10)  // Full coverage
            ->addStock('WARE-EU-2', 'SKU-FULL-PREF-2', 10)  // Full coverage
            ->addStock('WARE-EU-1', 'SKU-FULL-PREF-1', 3)   // Partial only
            ->addStock('WARE-EU-1', 'SKU-FULL-PREF-2', 2)   // Partial only
            ->build();

        // Order: Both need 5 units
        $this->createReservationWithLines('ORD-FULL-PREF-001', [
            ['productSku' => 'SKU-FULL-PREF-1', 'qty' => 5],
            ['productSku' => 'SKU-FULL-PREF-2', 'qty' => 5],
        ]);

        $res = $this->readReservation('ORD-FULL-PREF-001');
        $map = $this->lineMap($res);

        // Assertions
        self::assertSame(StockReservationStatus::RESERVED->value, $res['status']);
        $wh1 = $map['SKU-FULL-PREF-1']['warehouse'];
        $wh2 = $map['SKU-FULL-PREF-2']['warehouse'];
        self::assertSame($wh1, $wh2, 'Should consolidate to single warehouse');
        self::assertSame('WARE-EU-2', $wh1, 'Should prefer warehouse that fully covers all SKUs');
    }

    /**
     * Helper to create reservation with multiple lines
     */
    private function createReservationWithLines(string $number, array $lines): void
    {
        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'number' => $number,
                'lines' => $lines,
            ], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
    }
}

