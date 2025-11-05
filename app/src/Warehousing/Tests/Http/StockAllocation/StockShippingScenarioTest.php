<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http\StockAllocation;

use App\Tests\Support\WebTestCase;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Service\StockItemService;
use App\Warehousing\Tests\DataFixtures\WarehouseTestFixture;
use App\Warehousing\Tests\Helpers\StockAllocationScenarioBuilder;
use App\Warehousing\Tests\Helpers\StockItemTestHelpers;
use App\Warehousing\Tests\Helpers\StockReservationTestHelpers;

final class StockShippingScenarioTest extends WebTestCase
{
    use StockReservationTestHelpers;
    use StockItemTestHelpers;

    private StockAllocationScenarioBuilder $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRepositories();

        $this->scenario = new StockAllocationScenarioBuilder($this->em, $this->c);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            WarehouseTestFixture::class,
        ];
    }

    /**
     * SCENARIO: A fully reserved single-line reservation is shipped.
     * EXPECTED: On-hand decrements by ordered qty; reservation and line statuses become SHIPPED.
     * @throws \JsonException
     */
    public function test_ship_full_reservation_decrements_stock_and_marks_shipped(): void
    {
        $this->expectSuccess();

        $sku = 'SHIP-OK-1';
        $this->scenario->addStock('WARE-EU-1', $sku, 5)->build();

        $number = 'ORD-SHIP-OK';
        $qty = 2;
        $created = $this->createReservation($number, $sku, $qty);
        $whCode = $created['lines'][0]['warehouse']['code'];
        $onHandBefore = $this->onHandAt($whCode, $sku);

        self::assertSame(202, $this->shipReservation($number));

        $onHandAfter = $this->onHandAt($whCode, $sku);
        self::assertSame($onHandBefore - $qty, $onHandAfter);

        $res = $this->readReservation($number);
        self::assertSame(StockReservationStatus::SHIPPED->value, $res['status']);
        $map = $this->mapReservationBySku($res);
        self::assertSame(StockReservationLineStatus::SHIPPED->value, $map[$sku]['status']);
    }

    /**
     * SCENARIO: Shipping a non-existing reservation.
     * EXPECTED: Returns 404.
     */
    public function test_ship_unknown_reservation_returns_404(): void
    {
        $this->expectError();
        $this->shipReservation('ORD-DOES-NOT-EXIST', 404);
    }

    /**
     * SCENARIO: Shipping after cancel.
     * EXPECTED: Shipping is rejected and status remains CANCELED.
     */
    public function test_ship_after_cancel_is_rejected(): void
    {
        $this->expectError();

        $sku = 'SHIP-CANCEL';
        $this->scenario->addStock('WARE-EU-1', $sku, 3)->build();

        $number = 'ORD-SHIP-CANCELLED';
        $this->createReservation($number, $sku, 1);

        $this->cancelReservation($number, 202);
        $this->shipReservation($number, 409);

        $res = $this->readReservation($number);
        self::assertSame(StockReservationStatus::CANCELED->value, $res['status']);
    }

    /**
     * SCENARIO: Shipping the same reservation twice.
     * EXPECTED: First ships and decrements on-hand; second returns 409 and does not decrement again.
     * @throws \JsonException
     */
    public function test_ship_twice_does_not_decrement_stock_twice(): void
    {
        $this->expectSuccess();

        $sku = 'SHIP-TWICE';
        $wareCode = 'WARE-EU-1';
        $onHandBefore = 10;
        $this->scenario->addStock($wareCode, $sku, $onHandBefore)->build();

        $number = 'ORD-SHIP-TWICE';
        $qty = 3;
        $this->createReservation($number, $sku, $qty);
        $this->shipReservation($number);

        $onHandAfterFirst = $this->onHandAt($wareCode, $sku);
        self::assertSame($onHandBefore - $qty, $onHandAfterFirst);

        $this->shipReservation($number, 409);

        $onHandAfterSecond = $this->onHandAt($wareCode, $sku);
        self::assertSame($onHandAfterFirst, $onHandAfterSecond);

        $res = $this->readReservation($number);
        self::assertSame(StockReservationStatus::SHIPPED->value, $res['status']);
    }
}


