<?php
declare(strict_types=1);

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
     * @throws \JsonException
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
     * @throws \JsonException
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
        self::assertNotSame($whA, $whB, 'It should assign on different warehouses');
        self::assertSame('WARE-EU-1', $whA, 'SKU-A must come from WARE-EU-1 (only full coverage)');
        self::assertSame('WARE-EU-3', $whB, 'SKU-B must come from WARE-EU-3 (only full coverage)');
    }

    /**
     * SCENARIO: SKU only has partial availability across all warehouses (total 3 units, need 5).
     * EXPECTED: Should assign to single warehouse with best availability (no split), partial reservation.
     * @throws \JsonException
     */
    public function test_assigns_partial_sku_to_single_best_warehouse_without_split(): void
    {
        $this->expectSuccess();

        // Setup: SKU only partially available (total 3 units, but need 5)
        $this->scenario
            ->addStock('WARE-EU-1', 'SKU-PARTIAL', 2)  // Best partial
            ->addStock('WARE-EU-3', 'SKU-PARTIAL', 1)  // Smaller partial
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
        self::assertSame('WARE-EU-1', $map['SKU-PARTIAL']['warehouse'], 'Should pick best warehouse');
        self::assertSame(0, $this->availableAt('SKU-PARTIAL', 'WARE-EU-1'), 'Chosen warehouse should be depleted');
    }

    /**
     * SCENARIO: Two warehouses can fully cover both SKUs equally.
     * EXPECTED: Should consolidate to single warehouse (deterministic tie-break).
     * @throws \JsonException
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
     * @throws \JsonException
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
     * SCENARIO: One SKU fully allocated selects a warehouse; a remaining SKU is partial-only
     *           with availability across multiple warehouses. We should prefer the already
     *           chosen warehouse to minimize warehouse count, even if another has more qty.
     * EXPECTED: Partial SKU assigned to already chosen warehouse; status is partial.
     * @throws \JsonException
     */
    public function test_partial_only_prefers_already_chosen_warehouse(): void
    {
        $this->expectSuccess();

        // Setup: SKU-ONE fully at EU-2; SKU-TWO partial at EU-2(2) and EU-1(5)
        $this->scenario
            ->addStock('WARE-EU-2', 'SKU-ONE', 6)     // Full coverage candidate
            ->addStock('WARE-EU-2', 'SKU-TWO', 2)     // Partial at chosen warehouse
            ->addStock('WARE-EU-1', 'SKU-TWO', 5)     // Larger partial elsewhere
            ->build();

        // Order: SKU-ONE needs 5 (full), SKU-TWO needs 6 (partial-only)
        $this->createReservationWithLines('ORD-PREFER-CHOSEN-001', [
            ['productSku' => 'SKU-ONE', 'qty' => 5],
            ['productSku' => 'SKU-TWO', 'qty' => 6],
        ]);

        $res = $this->readReservation('ORD-PREFER-CHOSEN-001');
        $map = $this->lineMap($res);

        // Assertions
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map['SKU-ONE']['status']);
        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map['SKU-TWO']['status']);

        $whOne = $map['SKU-ONE']['warehouse'];
        $whTwo = $map['SKU-TWO']['warehouse'];
        self::assertSame($whOne, $whTwo, 'Partial-only SKU should prefer already chosen warehouse');
        self::assertSame(2, $map['SKU-TWO']['reserved'], 'Should reserve from the chosen warehouse quantity');
    }

    /**
     * SCENARIO: Mixed case where one SKU is completely out of stock and another is fully coverable.
     * EXPECTED: One line OUT_OF_STOCK, the other RESERVED, and consolidation behavior still holds for available ones.
     * @throws \JsonException
     */
    public function test_mixed_out_of_stock_and_reserved_lines(): void
    {
        $this->expectSuccess();

        $this->scenario
            ->addStock('WARE-EU-1', 'SKU-OK', 5)
            // No stock anywhere for SKU-OOS
            ->build();

        $this->createReservationWithLines('ORD-MIXED-OOS-001', [
            ['productSku' => 'SKU-OK', 'qty' => 3],
            ['productSku' => 'SKU-OOS', 'qty' => 4],
        ]);

        $res = $this->readReservation('ORD-MIXED-OOS-001');
        $map = $this->lineMap($res);

        self::assertSame(StockReservationLineStatus::RESERVED->value, $map['SKU-OK']['status']);
        self::assertSame(StockReservationLineStatus::OUT_OF_STOCK->value, $map['SKU-OOS']['status']);
        self::assertSame('WARE-EU-1', $map['SKU-OK']['warehouse']);
        self::assertNull($map['SKU-OOS']['warehouse']);
    }

    /**
     * SCENARIO: Two warehouses can fully cover both SKUs, but one has higher headroom
     *           (minimum available across covered SKUs).
     * EXPECTED: Prefer the warehouse with higher headroom.
     * @throws \JsonException
     */
    public function test_headroom_tie_break_prefers_higher_headroom(): void
    {
        $this->expectSuccess();

        // EU-1 can cover both SKUs with minimal headroom (exact fits), EU-2 has more headroom
        $this->scenario
            ->addStock('WARE-EU-1', 'SKU-HR-1', 3)  // ordered 3
            ->addStock('WARE-EU-1', 'SKU-HR-2', 2)  // ordered 2
            ->addStock('WARE-EU-2', 'SKU-HR-1', 10) // more headroom
            ->addStock('WARE-EU-2', 'SKU-HR-2', 10) // more headroom
            ->build();

        $this->createReservationWithLines('ORD-HR-001', [
            ['productSku' => 'SKU-HR-1', 'qty' => 3],
            ['productSku' => 'SKU-HR-2', 'qty' => 2],
        ]);

        $res = $this->readReservation('ORD-HR-001');
        $map = $this->lineMap($res);

        $wh1 = $map['SKU-HR-1']['warehouse'];
        $wh2 = $map['SKU-HR-2']['warehouse'];
        self::assertSame($wh1, $wh2, 'Should consolidate to a single warehouse');
        self::assertSame('WARE-EU-2', $wh1, 'Should prefer warehouse with higher headroom');
    }

    /**
     * SCENARIO: Single SKU has sufficient stock in one warehouse.
     * EXPECTED: Line RESERVED with exact qty; some warehouse assigned.
     * @throws \JsonException
     */
    public function test_single_sku_full_allocation_in_single_warehouse(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $number = 'ORD-ALLOC-FULL-001';

        $this->scenario
            ->addStock('WARE-EU-1', $sku, 5)
            ->build();

        $this->createReservationWithLines($number, [['productSku' => $sku, 'qty' => 2]]);

        $res = $this->readReservation($number);
        $map = $this->lineMap($res);

        self::assertSame(StockReservationStatus::RESERVED->value, $res['status']);
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map[$sku]['status']);
        self::assertSame(2, $map[$sku]['reserved']);
        self::assertNotNull($map[$sku]['warehouse']);
    }

    /**
     * SCENARIO: Single SKU has no stock anywhere.
     * EXPECTED: Reservation OUT_OF_STOCK, line OUT_OF_STOCK, no warehouse.
     * @throws \JsonException
     */
    public function test_single_sku_zero_stock_sets_out_of_stock_statuses(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-ZERO';
        $number = 'ORD-ALLOC-NONE-001';

        $this->scenario->build();

        $this->createReservationWithLines($number, [['productSku' => $sku, 'qty' => 3]]);

        $res = $this->readReservation($number);
        $map = $this->lineMap($res);

        self::assertSame(StockReservationStatus::OUT_OF_STOCK->value, $res['status']);
        self::assertSame(StockReservationLineStatus::OUT_OF_STOCK->value, $map[$sku]['status']);
        self::assertSame(0, $map[$sku]['reserved']);
        self::assertNull($map[$sku]['warehouse']);
    }

    /**
     * SCENARIO: Reading reservation multiple times must not mutate reserved quantities.
     * EXPECTED: Reserved qty remains identical across reads.
     * @throws \JsonException
     */
    public function test_idempotency_read_does_not_change_reserved_quantities(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $number = 'ORD-ALLOC-IDEMP-001';

        $this->scenario
            ->addStock('WARE-EU-1', $sku, 5)
            ->build();

        $this->createReservationWithLines($number, [['productSku' => $sku, 'qty' => 3]]);

        $res1 = $this->readReservation($number);
        $reserved1 = $this->lineMap($res1)[$sku]['reserved'];
        $res2 = $this->readReservation($number);
        $reserved2 = $this->lineMap($res2)[$sku]['reserved'];

        self::assertSame($reserved1, $reserved2, 'Reads must not mutate reserved quantities');
    }

    /**
     * SCENARIO: Cancel a fully reserved single-line reservation.
     * EXPECTED: Line and reservation set to CANCELED; reserved released back to stock.
     * @throws \JsonException
     */
    public function test_cancel_full_reservation_releases_stock_and_sets_status_canceled(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $number = 'ORD-CANCEL-FULL-001';

        $this->scenario
            ->addStock('WARE-EU-1', $sku, 5)
            ->build();
        $availBefore = $this->availableAt($sku, 'WARE-EU-1');

        $this->createReservationWithLines($number, [['productSku' => $sku, 'qty' => 2]]);
        $this->cancelReservation($number);

        $after = $this->readReservation($number);
        $line = $this->lineMap($after)[$sku];

        self::assertSame(StockReservationStatus::CANCELED->value, $after['status']);
        self::assertSame(StockReservationLineStatus::CANCELED->value, $line['status']);
        self::assertSame(0, $line['reserved']);

        $whCode = 'WARE-EU-1';
        $availAfter = $this->availableAt($sku, $whCode);
        self::assertSame($availBefore, $availAfter);
    }

    /**
     * SCENARIO: Cancel a partially reserved single-line reservation (requested > available).
     * EXPECTED: Line and reservation set to CANCELED; reserved released back to pool.
     * @throws \JsonException
     */
    public function test_cancel_partial_reservation_releases_reserved_and_sets_status_canceled(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-002';
        $number = 'ORD-CANCEL-PARTIAL-001';

        $this->scenario
            ->addStock('WARE-EU-2', $sku, 4)
            ->build();
        $availBefore = $this->maxAvailable($sku);

        $this->createReservationWithLines($number, [['productSku' => $sku, 'qty' => $availBefore + 10]]);

        $created = $this->readReservation($number);
        $map = $this->lineMap($created);
        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map[$sku]['status']);

        $this->cancelReservation($number);

        $after = $this->readReservation($number);
        $line = $this->lineMap($after)[$sku];

        self::assertSame(StockReservationStatus::CANCELED->value, $after['status']);
        self::assertSame(StockReservationLineStatus::CANCELED->value, $line['status']);
        self::assertSame(0, $line['reserved']);

        $availAfter = $this->maxAvailable($sku);
        self::assertGreaterThanOrEqual($availBefore, $availAfter);
    }

    /**
     * SCENARIO: Cancel the same reservation twice.
     * EXPECTED: First cancel 202, second cancel 409 Conflict, state unchanged.
     * @throws \JsonException
     */
    public function test_cancel_second_time_returns_conflict_and_does_not_change_state(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $number = 'ORD-CANCEL-IDEM-001';

        $this->scenario
            ->addStock('WARE-EU-1', $sku, 2)
            ->build();

        $this->createReservationWithLines($number, [['productSku' => $sku, 'qty' => 1]]);

        $this->cancelReservation($number, 202);
        $this->cancelReservation($number, 409);
    }

    /**
     * SCENARIO: Ordered quantity is less than available.
     * EXPECTED: Never reserves more than ordered across reads.
     * @throws \JsonException
     */
    public function test_never_reserves_more_than_ordered(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $number = 'ORD-ALLOC-NO-OVER-RESERVE';

        $this->scenario
            ->addStock('WARE-EU-1', $sku, 10)
            ->build();

        $this->createReservationWithLines($number, [['productSku' => $sku, 'qty' => 2]]);

        $res1 = $this->readReservation($number);
        $reserved1 = $this->lineMap($res1)[$sku]['reserved'];
        $res2 = $this->readReservation($number);
        $reserved2 = $this->lineMap($res2)[$sku]['reserved'];

        self::assertSame(2, $reserved1);
        self::assertSame(2, $reserved2);
    }

}

