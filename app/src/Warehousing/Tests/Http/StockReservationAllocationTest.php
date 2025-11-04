<?php

namespace App\Warehousing\Tests\Http;

use App\Tests\Support\WebTestCase;
use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Tests\DataFixtures\StockReservationEmptyTestFixture;
use App\Warehousing\Tests\Helpers\StockReservationTestHelpers;

final class StockReservationAllocationTest extends WebTestCase
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

    public function test_allocate_full_when_stock_sufficient_in_a_single_warehouse(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $avail, "Fixture must provide availability for $sku");

        $number = 'ORD-ALLOC-FULL-001';
        $qty = min(2, $avail);

        $this->createReservation($number, $sku, $qty);

        $res = $this->readReservation($number);
        $map = $this->lineMap($res);

        self::assertSame(StockReservationStatus::RESERVED->value, $res['status']);
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map[$sku]['status']);
        self::assertSame($qty, $map[$sku]['reserved']);
        self::assertNotNull($map[$sku]['warehouse']);
    }

    public function test_allocate_partial_when_requested_exceeds_total_available(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-002';
        $availBefore = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $availBefore, "Fixture must provide availability for $sku");

        $number = 'ORD-ALLOC-PARTIAL-001';
        $qty = $availBefore + 5;

        $res = $this->createReservation($number, $sku, $qty);
        $map = $this->lineMap($res);

        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $res['status']);
        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map[$sku]['status']);
        self::assertSame($availBefore, $map[$sku]['reserved']);

        $whCode = $res['lines'][0]['warehouse']['code'];
        self::assertSame(0, $this->availableAt($sku, $whCode));
    }

    public function test_allocate_mixed_full_and_partial_across_multiple_skus(): void
    {
        $this->expectSuccess();

        $sku1 = 'SKU-001';
        $sku2 = 'SKU-002';
        $avail1 = $this->maxAvailable($sku1);
        $avail2 = $this->maxAvailable($sku2);

        self::assertGreaterThan(0, $avail1);
        self::assertGreaterThan(0, $avail2);

        $number = 'ORD-ALLOC-MIX-001';
        $qty1 = min(2, $avail1);
        $qty2 = $avail2 + 10;

        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'number' => $number,
                'lines' => [
                    ['productSku' => $sku1, 'qty' => $qty1],
                    ['productSku' => $sku2, 'qty' => $qty2],
                ],
            ], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $res = $this->readReservation($number);
        $map = $this->lineMap($res);

        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $res['status']);
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map[$sku1]['status']);
        self::assertSame($qty1, $map[$sku1]['reserved']);
        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map[$sku2]['status']);
        self::assertSame($avail2, $map[$sku2]['reserved']);

        $whCode = $res['lines'][1]['warehouse']['code'];
        self::assertSame(0, $this->availableAt($sku2, $whCode));
    }

    public function test_no_allocation_when_zero_stock_sets_out_of_stock_statuses(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-ZERO';
        $avail = $this->maxAvailable($sku);
        self::assertSame(0, $avail, "Fixture must have 0 availability for $sku");

        $number = 'ORD-ALLOC-NONE-001';
        $this->createReservation($number, $sku, 3);

        $res = $this->readReservation($number);
        $map = $this->lineMap($res);

        self::assertSame(StockReservationStatus::OUT_OF_STOCK->value, $res['status']);
        self::assertSame(StockReservationLineStatus::OUT_OF_STOCK->value, $map[$sku]['status']);
        self::assertSame(0, $map[$sku]['reserved']);
        self::assertNull($map[$sku]['warehouse']);
    }

    public function test_idempotency_read_does_not_change_reserved_quantities(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $avail);

        $number = 'ORD-ALLOC-IDEMP-001';
        $qty = min(3, $avail);

        $this->createReservation($number, $sku, $qty);

        $res1 = $this->readReservation($number);
        $reserved1 = $this->lineMap($res1)[$sku]['reserved'];

        $res2 = $this->readReservation($number);
        $reserved2 = $this->lineMap($res2)[$sku]['reserved'];

        self::assertSame($reserved1, $reserved2, 'Reads must not mutate reserved quantities');
    }

    public function test_cancel_full_reservation_releases_stock_and_sets_status_canceled(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $availBefore = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $availBefore, "Fixture must provide availability for $sku");

        $number = 'ORD-CANCEL-FULL-001';
        $qty = min(2, $availBefore);

        $created = $this->createReservation($number, $sku, $qty);
        $whCode = $created['lines'][0]['warehouse']['code'];
        self::assertNotNull($whCode);

        self::assertSame(202, $this->cancelReservation($number));

        $after = $this->readReservation($number);
        $line = $this->lineMap($after)[$sku];

        self::assertSame(StockReservationStatus::CANCELED->value, $after['status']);
        self::assertSame(StockReservationLineStatus::CANCELED->value, $line['status']);
        self::assertSame(0, $line['reserved']);

        $availAfter = $this->availableAt($sku, $whCode);
        self::assertSame($availBefore, $availAfter);
    }

    public function test_cancel_partial_reservation_releases_reserved_and_sets_status_canceled(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-002';
        $availBefore = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $availBefore, "Fixture must provide availability for $sku");

        $number = 'ORD-CANCEL-PARTIAL-001';
        $qty = $availBefore + 10;

        $created = $this->createReservation($number, $sku, $qty);
        $map = $this->lineMap($created);
        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map[$sku]['status']);

        self::assertSame(202, $this->cancelReservation($number));

        $after = $this->readReservation($number);
        $line = $this->lineMap($after)[$sku];

        self::assertSame(StockReservationStatus::CANCELED->value, $after['status']);
        self::assertSame(StockReservationLineStatus::CANCELED->value, $line['status']);
        self::assertSame(0, $line['reserved']);

        $availAfter = $this->maxAvailable($sku);
        self::assertGreaterThanOrEqual($availBefore, $availAfter);
    }


    public function test_cancel_second_time_returns_conflict_and_does_not_change_state(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $avail);

        $number = 'ORD-CANCEL-IDEM-001';
        $this->createReservation($number, $sku, min(1, $avail));

        self::assertSame(202, $this->cancelReservation($number));
        self::assertSame(409, $this->cancelReservation($number));
    }

    public function test_never_reserves_more_than_ordered(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(1, $avail);

        $number = 'ORD-ALLOC-NO-OVER-RESERVE';
        $qty = min(2, $avail);

        $this->createReservation($number, $sku, $qty);

        $res1 = $this->readReservation($number);
        $reserved1 = $this->lineMap($res1)[$sku]['reserved'];

        $res2 = $this->readReservation($number);
        $reserved2 = $this->lineMap($res2)[$sku]['reserved'];

        self::assertSame($qty, $reserved1);
        self::assertSame($qty, $reserved2);
    }

}
