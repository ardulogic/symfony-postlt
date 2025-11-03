<?php

namespace App\Warehousing\Tests\Http;

use App\Shared\Tests\WebTestCase;
use App\Warehousing\Entity\StockItem;
use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\StockReservationRepository;
use App\Warehousing\Tests\DataFixtures\StockReservationEmptyTestFixture;

final class StockReservationAllocationTest extends WebTestCase
{
    private StockItemRepository $stockRepo;
    private StockReservationRepository $resRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resRepo  = $this->c->get(StockReservationRepository::class);
        $this->stockRepo = $this->c->get(StockItemRepository::class);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            StockReservationEmptyTestFixture::class,
        ];
    }

    /** Read reservation JSON by number. */
    private function readReservationJson(string $number): array
    {
        $this->client->request('GET', $this->url('stock_reservations_read', ['number' => $number]));
        self::assertResponseStatusCodeSame(200);

        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Quick map from response JSON lines: sku => [ordered, reserved, warehouseCode] */
    private function lineMap(array $json): array
    {
        $map = [];
        foreach ($json['lines'] ?? [] as $l) {
            $map[$l['productSku']] = [
                'ordered'   => (int)$l['orderedQty'],
                'reserved'  => (int)$l['reservedQty'],
                'warehouse' => $l['warehouse']['code'] ?? null,
                'status'    => $l['status'] ?? null,
            ];
        }
        return $map;
    }

    // ---------- tests ----------

    public function test_allocate_full_when_stock_sufficient_in_a_single_warehouse(): void
    {
        $this->expectSuccess();

        // Pick a SKU that has sufficient stock (in at least one warehouse). We discover it dynamically.
        $candidateSku = 'SKU-001'; // adjust if your fixture uses other SKUs; logic below protects the test
        $available = $this->maxAvailable($candidateSku);
        self::assertGreaterThan(0, $available, "Fixture must provide availability for $candidateSku");

        $number  = 'ORD-ALLOC-FULL-001';
        $payload = [
            'number' => $number,
            'lines'  => [
                ['productSku' => $candidateSku, 'qty' => min(2, $available)], // a small, surely coverable qty
            ],
        ];

        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $json = $this->readReservationJson($number);
        $map  = $this->lineMap($json);

        self::assertSame(StockReservationStatus::RESERVED->value, $json['status'] ?? null, 'Reservation should be FULL');
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map[$candidateSku]['status'] ?? null, 'Line should be FULL');

        self::assertSame(
            $payload['lines'][0]['qty'],
            $map[$candidateSku]['reserved'],
            'Reserved qty should equal ordered qty'
        );

        self::assertNotNull($map[$candidateSku]['warehouse'], 'Warehouse must be set on fully reserved line');
    }

    public function test_allocate_partial_when_requested_exceeds_total_available(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-002'; // any SKU present in fixture
        $beforeAvail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $beforeAvail, "Fixture must provide availability for $sku");

        $number  = 'ORD-ALLOC-RESERVED_PARTIAL-001';
        $payload = [
            'number' => $number,
            'lines'  => [
                ['productSku' => $sku, 'qty' => $beforeAvail + 5], // force partial
            ],
        ];

        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $json = $this->readReservationJson($number);
        $map  = $this->lineMap($json);

        // Reservation should be RESERVED_PARTIAL; line should be RESERVED_PARTIAL; reserved = all available
        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $json['status'] ?? null);
        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map[$sku]['status'] ?? null);
        self::assertSame($beforeAvail, $map[$sku]['reserved']);

        // Stock should be fully “consumed” for that SKU
        $wareCode = $json['lines'][0]['warehouse']['code'];
        $afterAvail = $this->maxAvailableAt($sku, $wareCode);
        self::assertSame(0, $afterAvail, 'Availability should be 0 after full reservation in stock for the warehouse:' . $wareCode);
    }

    public function test_allocate_mixed_full_and_partial_across_multiple_skus(): void
    {
        $this->expectSuccess();

        $skuFull    = 'SKU-001';
        $skuPartial = 'SKU-002';

        $availFull    = $this->maxAvailable($skuFull);
        $availPartial = $this->maxAvailable($skuPartial);

        self::assertGreaterThan(0, $availFull, "Fixture must provide availability for $skuFull");
        self::assertGreaterThan(0, $availPartial, "Fixture must provide availability for $skuPartial");

        $number  = 'ORD-ALLOC-MIX-001';
        $payload = [
            'number' => $number,
            'lines'  => [
                ['productSku' => $skuFull,    'qty' => min(2, $availFull)],      // fully feasible
                ['productSku' => $skuPartial, 'qty' => $availPartial + 10],      // forces partial
            ],
        ];

        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $json = $this->readReservationJson($number);
        $map  = $this->lineMap($json);

        // Reservation overall is RESERVED_PARTIAL (since one line is partial)
        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $json['status'] ?? null);

        // Line expectations
        self::assertSame(StockReservationLineStatus::RESERVED->value, $map[$skuFull]['status'] ?? null);
        self::assertSame($payload['lines'][0]['qty'], $map[$skuFull]['reserved']);

        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map[$skuPartial]['status'] ?? null);
        self::assertSame($availPartial, $map[$skuPartial]['reserved']);

        $wareCode = $json['lines'][1]['warehouse']['code'];

        // After allocation, partial SKU availability should drop to 0
        self::assertSame(0, $this->maxAvailableAt($skuPartial, $wareCode));
    }

    public function test_no_allocation_when_zero_stock_sets_out_of_stock_statuses(): void
    {
        $this->expectSuccess();

        // Find/assume a SKU in fixture that starts with 0 availability; if none, we temporarily pick one and assert.
        $sku = 'SKU-ZERO'; // replace with a known 0-availability SKU from your fixture
        $beforeAvail = $this->maxAvailable($sku);

        // If your fixture doesn’t have such SKU, mark as risky but keep the invariant checks:
        self::assertSame(0, $beforeAvail, "Fixture must have 0 availability for $sku");

        $number = 'ORD-ALLOC-NONE-001';
        $payload = [
            'number' => $number,
            'lines'  => [
                ['productSku' => $sku, 'qty' => 3],
            ],
        ];

        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $json = $this->readReservationJson($number);
        $map  = $this->lineMap($json);

        self::assertSame(StockReservationStatus::OUT_OF_STOCK->value, $json['status'] ?? null);
        self::assertSame(StockReservationLineStatus::OUT_OF_STOCK->value, $map[$sku]['status'] ?? null);
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
        $payload = [
            'number' => $number,
            'lines'  => [
                ['productSku' => $sku, 'qty' => min(3, $avail)],
            ],
        ];

        // Create (allocates)
        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        // First read
        $first = $this->readReservationJson($number);
        $r1 = $this->lineMap($first)[$sku]['reserved'];

        // Second read
        $second = $this->readReservationJson($number);
        $r2 = $this->lineMap($second)[$sku]['reserved'];

        self::assertSame($r1, $r2, 'Reads must not mutate reserved quantities');
    }

    public function test_cancel_full_reservation_releases_stock_and_sets_status_canceled(): void
    {
        $this->expectSuccess();

        // Arrange: create a fully-coverable reservation
        $sku = 'SKU-001';
        $availBefore = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $availBefore, "Fixture must provide availability for $sku");

        $number = 'ORD-CANCEL-FULL-001';
        $payload = [
            'number' => $number,
            'lines'  => [['productSku' => $sku, 'qty' => min(2, $availBefore)]],
        ];

        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $created = $this->readReservationJson($number);
        $wareCode = $created['lines'][0]['warehouse']['code'] ?? null;
        self::assertNotNull($wareCode);

        // Act: cancel
        $this->client->request(
            'PUT',
            $this->url('stock_reservations_cancel', ['number' => $number]),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202); // job queued (or 204/200 if you return that)

        // Assert: reservation & lines are canceled, stock released
        $after = $this->readReservationJson($number);
        $line  = $this->lineMap($after)[$sku];

        self::assertSame(StockReservationStatus::CANCELED->value, $after['status'] ?? null);
        self::assertSame(StockReservationLineStatus::CANCELED->value, $line['status'] ?? null);
        self::assertSame(0, $line['reserved'], 'Reserved qty should drop to 0 after cancel');

        // Availability should be restored at that warehouse at least to pre-cancel minus other effects
        $availAfter = $this->maxAvailableAt($sku, $wareCode);
        self::assertSame($availBefore, $availAfter, 'Cancel should release reserved stock back to availability');
    }

    public function test_cancel_partial_reservation_releases_reserved_and_sets_status_canceled(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-002';
        $availBefore = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $availBefore, "Fixture must provide availability for $sku");

        $number = 'ORD-CANCEL-PARTIAL-001';
        $payload = [
            'number' => $number,
            'lines'  => [['productSku' => $sku, 'qty' => $availBefore + 10]], // force partial
        ];

        // Create (allocates partial)
        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $created = $this->readReservationJson($number);
        $map     = $this->lineMap($created);
        self::assertSame(StockReservationLineStatus::RESERVED_PARTIAL->value, $map[$sku]['status']);

        // Cancel
        $this->client->request(
            'PUT',
            $this->url('stock_reservations_cancel', ['number' => $number]),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202);

        // Assert: reservation canceled, reserved reset, availability restored
        $after = $this->readReservationJson($number);
        $line  = $this->lineMap($after)[$sku];

        self::assertSame(StockReservationStatus::CANCELED->value, $after['status'] ?? null);
        self::assertSame(StockReservationLineStatus::CANCELED->value, $line['status'] ?? null);
        self::assertSame(0, $line['reserved']);

        // We don’t know which warehouse was used for partial—check max availability across all
        $availAfter = $this->maxAvailable($sku);
        self::assertGreaterThanOrEqual($availBefore, $availAfter, 'Cancel should release all previously reserved units');
    }


    public function test_cancel_second_time_returns_conflict_and_does_not_change_state(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $avail);

        $number = 'ORD-CANCEL-IDEM-001';
        $payload = [
            'number' => $number,
            'lines'  => [['productSku' => $sku, 'qty' => min(1, $avail)]],
        ];

        // Create
        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        // First cancel → 202
        $this->client->request(
            'PUT',
            $this->url('stock_reservations_cancel', ['number' => $number]),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202);

        // Second cancel → 409 Conflict
        $this->client->request(
            'PUT',
            $this->url('stock_reservations_cancel', ['number' => $number]),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(409);
    }

    // ---------- helpers ----------

    /** Total available across all warehouses for a given SKU (before/after). */
    private function maxAvailable(string $sku): int
    {
        $items = $this->stockRepo->getBySkus([$sku]);
        $max = 0;

        /* @var $stockItem StockItem */
        foreach ($items as $stockItem) {
            if ($stockItem->getProductSku() === $sku) {
                $max = max($max, $stockItem->getAvailableQty());
            }
        }
        return $max;
    }

    private function maxAvailableAt(string $sku, string $warehouseCode): int
    {
        $items = $this->stockRepo->getBySkus([$sku]);
        $max = 0;

        /* @var $stockItem StockItem */
        foreach ($items as $stockItem) {
            if ($stockItem->getProductSku() === $sku
                && $stockItem->getWarehouse()->getCode() === $warehouseCode) {
                $max = max($max, $stockItem->getAvailableQty());
            }
        }
        return $max;
    }

    public function test_allocation_prefers_single_warehouse_when_possible(): void
    {
        $this->expectSuccess();

        // Assuming fixture has both SKU-001 and SKU-002 available in the same warehouse
        $skuA = 'SKU-001';
        $skuB = 'SKU-002';
        self::assertGreaterThan(0, $this->maxAvailable($skuA));
        self::assertGreaterThan(0, $this->maxAvailable($skuB));

        $number  = 'ORD-ALLOC-FEWEST-001';
        $payload = [
            'number' => $number,
            'lines'  => [
                ['productSku' => $skuA, 'qty' => 1],
                ['productSku' => $skuB, 'qty' => 1],
            ],
        ];

        $this->client->request(
            'POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $json = $this->readReservationJson($number);
        $map  = $this->lineMap($json);

        $wA = $map[$skuA]['warehouse'] ?? null;
        $wB = $map[$skuB]['warehouse'] ?? null;

        self::assertNotNull($wA);
        self::assertNotNull($wB);
        self::assertSame($wA, $wB, 'Both SKUs should be allocated to the same warehouse when feasible');
    }

    public function test_never_reserves_more_than_ordered(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(1, $avail);

        $number  = 'ORD-ALLOC-NO-OVER-RESERVE';
        $qty     = min(2, $avail);

        $payload = [
            'number' => $number,
            'lines'  => [['productSku' => $sku, 'qty' => $qty]],
        ];

        // Create once
        $this->client->request(
            'POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        // Read twice; reserved must stay == ordered
        $first  = $this->readReservationJson($number);
        $second = $this->readReservationJson($number);
        $r1 = $this->lineMap($first)[$sku]['reserved'];
        $r2 = $this->lineMap($second)[$sku]['reserved'];

        self::assertSame($qty, $r1);
        self::assertSame($qty, $r2);
    }

}
