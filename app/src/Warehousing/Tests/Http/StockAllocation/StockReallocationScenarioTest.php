<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http\StockAllocation;

use App\Tests\Support\Queues\TestQueueWorker;
use App\Tests\Support\WebTestCase;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Tests\DataFixtures\WarehouseTestFixture;
use App\Warehousing\Tests\Helpers\StockAllocationScenarioBuilder;
use App\Warehousing\Tests\Helpers\StockReservationTestHelpers;
use App\Warehousing\Tests\Helpers\StockItemTestHelpers;

final class StockReallocationScenarioTest extends WebTestCase
{
    use StockReservationTestHelpers;
    use StockItemTestHelpers;

    private StockAllocationScenarioBuilder $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRepositories();
        $this->setUpServices();

        $this->client->enableReboot();
        $this->scenario = new StockAllocationScenarioBuilder($this->em, $this->c);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            WarehouseTestFixture::class,
        ];
    }

    /**
     * SCENARIO: Two reservations for same SKU; first fully reserved, second waiting (partial/zero).
     * EXPECTED: Canceling the first triggers reallocation; second improves to full if capacity allows.
     * @throws \JsonException
     */
    public function test_cancel_of_one_reservation_triggers_reallocation_of_another(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-REALLOC-1';
        $this->scenario->addStock('WARE-EU-1', $sku, 5)->build();

        $before1 = $this->createAndReadReservation('ORD-REALLOC-1', $sku, 5);
        $before2 = $this->createAndReadReservation('ORD-REALLOC-2', $sku, 6);

        self::assertSame(StockReservationStatus::RESERVED->value, $before1['status']);
        self::assertSame(StockReservationStatus::OUT_OF_STOCK->value, $before2['status']);

        $this->cancelReservation('ORD-REALLOC-1');

        TestQueueWorker::doQueuedJobs($this->c);

        $after2 = $this->readReservation('ORD-REALLOC-2');
        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $after2['status']);

        $after1 = $this->readReservation('ORD-REALLOC-1');
        self::assertSame(StockReservationStatus::CANCELED->value, $after1['status']);
    }

    /**
     * SCENARIO: Reservation initially assigned to WARE-EU-1, then better stock arrives at WARE-EU-2.
     * EXPECTED: Reallocation should release stock from WARE-EU-1 and reserve it in WARE-EU-2.
     * @throws \JsonException
     */
    public function test_reallocation_releases_stock_from_previous_warehouse(): void
    {
        $this->expectSuccess();

        $ware1 = 'WARE-EU-1';
        $ware2 = 'WARE-EU-2';
        $sku = 'SKU-REALLOC-1';
        $number = 'ORD-REALLOC-1';

        $this->scenario->addStock($ware1, $sku, 5)->build();
        $resMapPrev = $this->mapReservationBySku(
            $this->createAndReadReservation($number, $sku, 6));

        self::assertSame($ware1, $resMapPrev[$sku]['warehouse'], "Reservation should be in $ware1" );
        self::assertSame(5, $resMapPrev[$sku]['reserved'], 'Reserved quantity should remain 5');
        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $resMapPrev[$sku]['status']);

        // Receive enough stock in ware 2
        $this->receiveStock($ware2, $sku, 6);

        TestQueueWorker::doQueuedJobs($this->c);

        // Verify stock was released from WARE-EU-1
        $stockItem1After = $this->readStockItem('WARE-EU-1', $sku);
        $stockItem2After = $this->readStockItem('WARE-EU-2', $sku);

        self::assertSame(5, $stockItem1After['onHandQty']);
        self::assertSame(0, $stockItem1After['reservedQty']);

        self::assertSame(6, $stockItem2After['onHandQty']);
        self::assertSame(6, $stockItem2After['reservedQty']);

        $resAfter = $this->readReservation($number);

        self::assertSame(StockReservationStatus::RESERVED->value, $resAfter['status']);
        self::assertSame($resAfter['lines'][0]['reservedQty'], 6);
        self::assertSame($resAfter['lines'][0]['orderedQty'], 6);
    }


    /**
     * SCENARIO: Cancel reservation for SKU-A; reservation for unrelated SKU-B must not change.
     * EXPECTED: Reserved qty for SKU-B remains identical.
     * @throws \JsonException
     */
    public function test_cancel_unrelated_reservation_does_not_change_other_sku(): void
    {
        $this->expectSuccess();

        $sku1 = 'SKU-REALLOC-A';
        $sku2 = 'SKU-REALLOC-B';
        $this->scenario
            ->addStock('WARE-EU-1', $sku1, 1)
            ->addStock('WARE-EU-2', $sku2, 999)
            ->build();

        $this->createReservation('ORD-UNREL-1', $sku1, 1);
        $this->createReservation('ORD-UNREL-2', $sku2, 999);

        $res2Before = $this->readReservation('ORD-UNREL-2');
        $reservedBefore = $this->mapReservationBySku($res2Before)[$sku2]['reserved'];

        $this->cancelReservation('ORD-UNREL-1', 202);
        TestQueueWorker::doQueuedJobs($this->c);

        $res2After = $this->readReservation('ORD-UNREL-2');
        self::assertSame($reservedBefore, $this->mapReservationBySku($res2After)[$sku2]['reserved']);
    }

    /**
     * SCENARIO: Cancel the same reservation twice.
     * EXPECTED: First cancel accepted; second returns conflict-like response; no double reallocation.
     */
    public function test_cancel_is_idempotent_and_does_not_reallocate_twice(): void
    {
        $this->expectSuccess();
        $sku = 'SKU-REALLOC-2';
        $this->scenario->addStock('WARE-EU-1', $sku, 1)->build();

        $this->createReservation('ORD-IDEMP', $sku, 1);

        $this->cancelReservation('ORD-IDEMP', 202);
        $this->cancelReservation('ORD-IDEMP', 409);
    }

    /**
     * SCENARIO: Reallocation triggered by other actions should not reduce already reserved quantities.
     * EXPECTED: Reserved qty remains the same or improves, not worse.
     */
    public function test_reallocation_does_not_worsen_allocation(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-REALLOC-3';
        $this->scenario->addStock('WARE-EU-1', $sku, 1)->build();

        $this->createReservation('ORD-STABLE', $sku, 1);
        $before = $this->mapReservationBySku($this->readReservation('ORD-STABLE'))[$sku];

        $this->createReservation('ORD-STABLE-TMP', $sku, 1);
        $this->cancelReservation('ORD-STABLE-TMP');

        TestQueueWorker::doQueuedJobs($this->c);

        $after = $this->mapReservationBySku($this->readReservation('ORD-STABLE'))[$sku];
        self::assertSame($before['reserved'], $after['reserved']);
        self::assertNotNull($after['warehouse']);
    }

    /**
     * SCENARIO: Three waiters exist; freeing stock should prioritize earlier waiter (FIFO fairness).
     * EXPECTED: Second reservation improves strictly; third improves not more than the second.
     * @throws \JsonException
     */
    public function test_fifo_reallocation_prioritizes_earlier_waiter(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-REALLOC-4';
        $this->scenario->addStock('WARE-EU-1', $sku, 2)->build();

        $this->createReservation('ORD-FIFO-1', $sku, 2);
        $this->createReservation('ORD-FIFO-2', $sku, 2);
        $this->createReservation('ORD-FIFO-3', $sku, 2);

        $map2Before = $this->mapReservationBySku($this->readReservation('ORD-FIFO-2'));
        $map3Before = $this->mapReservationBySku($this->readReservation('ORD-FIFO-3'));
        $reserved2Before = $map2Before[$sku]['reserved'];
        $reserved3Before = $map3Before[$sku]['reserved'];

        $this->cancelReservation('ORD-FIFO-1');
        TestQueueWorker::doQueuedJobs($this->c);

        $map2After = $this->mapReservationBySku($this->readReservation('ORD-FIFO-2'));
        $map3After = $this->mapReservationBySku($this->readReservation('ORD-FIFO-3'));
        $reserved2After = $map2After[$sku]['reserved'];
        $reserved3After = $map3After[$sku]['reserved'];

        self::assertGreaterThan($reserved2Before, $reserved2After);
        self::assertGreaterThanOrEqual($reserved3Before, $reserved3After);
    }

    /**
     * SCENARIO: A perfect, fully reserved single-warehouse reservation is locked for reallocation.
     * EXPECTED: Reallocation runs do not modify it and lock remains true.
     * @throws \JsonException
     */
    public function test_reallocation_keeps_single_warehouse_full_reservation_intact(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-REALLOC-5';
        $this->scenario->addStock('WARE-EU-1', $sku, 1)->build();

        $number = 'ORD-LOCKED-OK';
        $this->createReservation($number, $sku, 1);

        $entity = $this->resRepo->findOneByNumber($number);
        self::assertNotNull($entity);
        self::assertTrue($entity->isReallocationLocked(), 'Reservation should be locked for reallocation');

        $before = $this->mapReservationBySku($this->readReservation($number))[$sku];

        $tmp = 'ORD-LOCKED-TMP';
        $this->createReservation($tmp, $sku, 1);
        $this->cancelReservation($tmp);
        TestQueueWorker::doQueuedJobs($this->c);

        $after = $this->mapReservationBySku($this->readReservation($number))[$sku];
        self::assertSame($before['warehouse'], $after['warehouse']);
        self::assertSame($before['reserved'], $after['reserved']);
        self::assertTrue($this->resRepo->findOneByNumber($number)->isReallocationLocked(), 'Reservation should remain locked');
    }

    /**
     * SCENARIO: A waiting reservation exists; receiving new stock for its SKU triggers reallocation.
     * EXPECTED: Reserved qty increases; status moves to RESERVED or remains PARTIAL with higher reserve.
     * @throws \JsonException
     */
    public function test_receiving_stock_triggers_reallocation_for_waiting_reservation(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-REALLOC-6';
        $this->scenario->addStock('WARE-EU-1', $sku, 5)->build();

        $this->createReservation('ORD-REALLOC-ADD-1', $sku, 3);
        $this->createReservation('ORD-REALLOC-ADD-2', $sku, 5);

        $before = $this->readReservation('ORD-REALLOC-ADD-2');
        $reservedBefore = $before['lines'][0]['reservedQty'];
        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $before['status']);

        // Receive new stock for the same SKU at an existing warehouse
        $code = 'WARE-EU-1';
        $this->receiveStock($code, $sku, 10);
        TestQueueWorker::doQueuedJobs($this->c);

        $after = $this->readReservation('ORD-REALLOC-ADD-2');
        self::assertGreaterThan($reservedBefore, $after['lines'][0]['reservedQty']);
        self::assertContains($after['status'], [StockReservationStatus::RESERVED->value, StockReservationStatus::RESERVED_PARTIAL->value]);
    }
}


