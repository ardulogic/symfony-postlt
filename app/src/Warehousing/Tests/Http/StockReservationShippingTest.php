<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http;

use App\Shared\Tests\WebTestCase;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Tests\DataFixtures\StockReservationEmptyTestFixture;
use App\Warehousing\Tests\StockReservationTestHelpers;

final class StockReservationShippingTest extends WebTestCase
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

    public function test_ship_full_reservation_decrements_stock_and_marks_shipped(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $avail, 'Fixture must provide available stock for SKU-001');

        $number = 'ORD-SHIP-OK';
        $qty = 1;

        $created = $this->createReservation($number, $sku, $qty);
        $whCode = $created['lines'][0]['warehouse']['code'];
        $onHandBefore = $this->onHandAt($whCode, $sku);

        self::assertSame(202, $this->shipReservation($number));

        $this->em->clear();
        $onHandAfter = $this->onHandAt($whCode, $sku);

        self::assertSame($onHandBefore - $qty, $onHandAfter);

        $res = $this->readReservation($number);
        self::assertSame('shipped', strtolower((string)($res['status'] ?? '')));

        foreach ($res['lines'] as $line) {
            if (($line['productSku'] ?? null) === $sku) {
                self::assertSame('shipped', strtolower((string)($line['status'] ?? '')));
            }
        }
    }

    public function test_ship_unknown_reservation_returns_404(): void
    {
        $this->expectError();

        self::assertSame(404, $this->shipReservation('ORD-DOES-NOT-EXIST'));
    }

    public function test_ship_after_cancel_is_rejected(): void
    {
        $this->expectError();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $avail);

        $number = 'ORD-SHIP-CANCELLED';
        $this->createReservation($number, $sku, 1);

        self::assertSame(202, $this->cancelReservation($number));
        self::assertSame(409, $this->shipReservation($number));

        $res = $this->readReservation($number);
        self::assertSame(StockReservationStatus::CANCELED->value, (string)($res['status'] ?? ''));
    }

    public function test_ship_twice_does_not_decrement_stock_twice(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $avail);

        $number = 'ORD-SHIP-TWICE';
        $qty = min(2, $avail);

        $created = $this->createReservation($number, $sku, $qty);
        $whCode = $created['lines'][0]['warehouse']['code'];
        $onHandBefore = $this->onHandAt($whCode, $sku);

        self::assertSame(202, $this->shipReservation($number));

        $this->em->clear();
        $onHandAfterFirst = $this->onHandAt($whCode, $sku);
        self::assertSame($onHandBefore - $qty, $onHandAfterFirst);

        self::assertSame(409, $this->shipReservation($number));

        $this->em->clear();
        $onHandAfterSecond = $this->onHandAt($whCode, $sku);
        self::assertSame($onHandAfterFirst, $onHandAfterSecond);

        $res = $this->readReservation($number);
        self::assertSame(StockReservationStatus::SHIPPED->value, (string)($res['status'] ?? ''));
    }

}
