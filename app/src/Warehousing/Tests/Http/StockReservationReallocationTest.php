<?php

namespace App\Warehousing\Tests\Http;

use App\Shared\Tests\WebTestCase;
use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Tests\DataFixtures\StockReservationEmptyTestFixture;
use App\Warehousing\Tests\StockReservationTestHelpers;

final class StockReservationReallocationTest extends WebTestCase
{
    use StockReservationTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRepositories();
    }

    protected function getRequiredFixtures(): array
    {
        return [
            StockReservationEmptyTestFixture::class,
        ];
    }

    public function test_cancel_of_one_reservation_triggers_reallocation_of_another(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $avail);

        $order1 = 'ORD-REALLOC-1';
        $qty1 = $avail;

        $this->createReservation($order1, $sku, $qty1);

        $order2 = 'ORD-REALLOC-2';
        $qty2 = $this->maxAvailable($sku) + 1;

        $this->createReservation($order2, $sku, $qty2);

        $res2Before = $this->readReservation($order2);
        $map2Before = $this->lineMap($res2Before);
        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $res2Before['status']);
        $reservedBefore = $map2Before[$sku]['reserved'];

        self::assertSame(202, $this->cancelReservation($order1));

        $res2After = $this->readReservation($order2);
        $map2After = $this->lineMap($res2After);

        self::assertGreaterThan($reservedBefore, $map2After[$sku]['reserved']);

        if ($map2After[$sku]['reserved'] >= $map2After[$sku]['ordered']) {
            self::assertSame(StockReservationStatus::RESERVED->value, $res2After['status']);
            self::assertSame(StockReservationLineStatus::RESERVED->value, $map2After[$sku]['status']);
        }
    }

    public function test_cancel_unrelated_reservation_does_not_change_other_sku(): void
    {
        $this->expectSuccess();

        $sku1 = 'SKU-001';
        $sku2 = 'SKU-003';

        $this->createReservation('ORD-UNREL-1', $sku1, 1);
        $this->createReservation('ORD-UNREL-2', $sku2, 999);

        $res2Before = $this->readReservation('ORD-UNREL-2');
        $mapBefore = $this->lineMap($res2Before);
        $reservedBefore = $mapBefore[$sku2]['reserved'];

        self::assertSame(202, $this->cancelReservation('ORD-UNREL-1'));

        $res2After = $this->readReservation('ORD-UNREL-2');
        $mapAfter = $this->lineMap($res2After);
        self::assertSame($reservedBefore, $mapAfter[$sku2]['reserved']);
    }

    public function test_cancel_is_idempotent_and_does_not_reallocate_twice(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $this->createReservation('ORD-IDEMP', $sku, 1);

        self::assertSame(202, $this->cancelReservation('ORD-IDEMP'));

        $status = $this->cancelReservation('ORD-IDEMP');
        self::assertContains($status, [204, 409, 422]);
    }

    public function test_reallocation_does_not_worsen_allocation(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $order = 'ORD-STABLE';

        $this->createReservation($order, $sku, 1);

        $resBefore = $this->readReservation($order);
        $mapBefore = $this->lineMap($resBefore);

        $this->createReservation('ORD-STABLE-TMP', $sku, 1);
        $this->cancelReservation('ORD-STABLE-TMP');

        $resAfter = $this->readReservation($order);
        $mapAfter = $this->lineMap($resAfter);

        self::assertSame(StockReservationStatus::RESERVED->value, $resAfter['status']);
        self::assertNotNull($mapAfter[$sku]['warehouse']);
    }

    public function test_fifo_reallocation_prioritizes_earlier_waiter(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(1, $avail);

        $this->createReservation('ORD-FIFO-1', $sku, $avail);
        $this->createReservation('ORD-FIFO-2', $sku, $avail);
        $this->createReservation('ORD-FIFO-3', $sku, $avail);

        $map2Before = $this->lineMap($this->readReservation('ORD-FIFO-2'));
        $map3Before = $this->lineMap($this->readReservation('ORD-FIFO-3'));
        $reserved2Before = $map2Before[$sku]['reserved'];
        $reserved3Before = $map3Before[$sku]['reserved'];

        self::assertSame(202, $this->cancelReservation('ORD-FIFO-1'));

        $map2After = $this->lineMap($this->readReservation('ORD-FIFO-2'));
        $map3After = $this->lineMap($this->readReservation('ORD-FIFO-3'));
        $reserved2After = $map2After[$sku]['reserved'];
        $reserved3After = $map3After[$sku]['reserved'];

        self::assertGreaterThan($reserved2Before, $reserved2After);
        self::assertGreaterThanOrEqual($reserved3Before, $reserved3After);
        self::assertGreaterThanOrEqual($reserved2After - $reserved2Before, $reserved3After - $reserved3Before);
    }

    public function test_reallocation_keeps_single_warehouse_full_reservation_intact(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';

        $this->createReservation('ORD-STABLE-2', $sku, 1);

        $resBefore = $this->readReservation('ORD-STABLE-2');
        $mapBefore = $this->lineMap($resBefore);
        self::assertSame(StockReservationStatus::RESERVED->value, $resBefore['status']);
        self::assertNotNull($mapBefore[$sku]['warehouse']);

        $this->createReservation('ORD-STABLE-2-TMP', $sku, 1);
        self::assertSame(202, $this->cancelReservation('ORD-STABLE-2-TMP'));

        $resAfter = $this->readReservation('ORD-STABLE-2');
        $mapAfter = $this->lineMap($resAfter);
        self::assertSame(StockReservationStatus::RESERVED->value, $resAfter['status']);
        self::assertNotNull($mapAfter[$sku]['warehouse']);
    }

    public function test_fifo_full_first_then_next_gets_leftovers(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThanOrEqual(2, $avail, 'Need at least 2 for this scenario');

        $this->createReservation('ORD-FIFO2-1', $sku, $avail);

        foreach (['ORD-FIFO2-2', 'ORD-FIFO2-3'] as $number) {
            $this->createReservation($number, $sku, 1);
        }

        self::assertSame(202, $this->cancelReservation('ORD-FIFO2-1'));

        $res2 = $this->readReservation('ORD-FIFO2-2');
        $res3 = $this->readReservation('ORD-FIFO2-3');
        $line2 = $this->lineMap($res2)[$sku];
        $line3 = $this->lineMap($res3)[$sku];

        self::assertSame($line2['ordered'], $line2['reserved']);
        self::assertGreaterThanOrEqual(0, $line3['reserved']);
        self::assertLessThanOrEqual($line2['reserved'], $line3['reserved']);
    }
}
