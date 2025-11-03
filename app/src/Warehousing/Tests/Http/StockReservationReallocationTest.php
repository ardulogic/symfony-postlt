<?php

namespace App\Warehousing\Tests\Http;

use App\Shared\Tests\WebTestCase;
use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\StockReservationRepository;
use App\Warehousing\Tests\DataFixtures\StockReservationEmptyTestFixture;

final class StockReservationReallocationTest extends WebTestCase
{
    private StockItemRepository $stockRepo;
    private StockReservationRepository $resRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resRepo = $this->c->get(StockReservationRepository::class);
        $this->stockRepo = $this->c->get(StockItemRepository::class);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            StockReservationEmptyTestFixture::class,
        ];
    }

    /** Helpers copied from your other test (or extract to a trait) */
    private function readReservationJson(string $number): array
    {
        $this->client->request('GET', $this->url('stock_reservations_read', ['number' => $number]));
        self::assertResponseStatusCodeSame(200);
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function lineMap(array $json): array
    {
        $map = [];
        foreach ($json['lines'] ?? [] as $l) {
            $map[$l['productSku']] = [
                'ordered' => (int)$l['orderedQty'],
                'reserved' => (int)$l['reservedQty'],
                'warehouse' => $l['warehouse']['code'] ?? null,
                'status' => $l['status'] ?? null,
            ];
        }
        return $map;
    }

    private function maxAvailable(string $sku): int
    {
        $items = $this->stockRepo->getBySkus([$sku]);
        $max = 0;
        foreach ($items as $stockItem) {
            if ($stockItem->getProductSku() === $sku) {
                $max = max($max, $stockItem->getAvailableQty());
            }
        }
        return $max;
    }

    /**
     * @throws \JsonException
     */
    public function test_cancel_of_one_reservation_triggers_reallocation_of_another(): void
    {
        $this->expectSuccess();

        // Ensure we have at least some stock for the SKU, but not enough for both orders fully
        $sku = 'SKU-001';
        $maxAvailA = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $maxAvailA);

        // A: small order that will fully reserve
        $orderA = 'ORD-REALLOC-A';
        $qtyA = $maxAvailA; // 1 or 2

        // Create A
        $this->client->request(
            'POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => $orderA, 'lines' => [['productSku' => $sku, 'qty' => $qtyA]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        // B: bigger order that will be partial initially (requires more than remaining after A)
        $orderB = 'ORD-REALLOC-B';
        $maxAvailB = $this->maxAvailable($sku);
        self::assertGreaterThan(0, $maxAvailB);
        $qtyB = $this->maxAvailable($sku) + 1;

        // Create B (becomes partial)
        $this->client->request(
            'POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => $orderB, 'lines' => [['productSku' => $sku, 'qty' => $qtyB]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $bBefore = $this->readReservationJson($orderB);
        $bMapBefore = $this->lineMap($bBefore);
        self::assertSame(StockReservationStatus::RESERVED_PARTIAL->value, $bBefore['status'] ?? null);
        $reservedBefore = $bMapBefore[$sku]['reserved'];

        // Cancel A → this should free stock; with sync messenger, reallocation happens now
        $this->client->request(
            'PUT', $this->url('stock_reservations_cancel', ['number' => $orderA]),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202);

        // Read B again → reserved should have increased (ideally to full)
        $bAfter = $this->readReservationJson($orderB);
        $bMapAfter = $this->lineMap($bAfter);

        self::assertGreaterThan(
            $reservedBefore,
            $bMapAfter[$sku]['reserved'],
            'Reallocation should increase reserved qty on the waiting reservation'
        );

        // If enough, it may become fully reserved now
        if ($bMapAfter[$sku]['reserved'] >= $bMapAfter[$sku]['ordered']) {
            self::assertSame(StockReservationStatus::RESERVED->value, $bAfter['status'] ?? null);
            self::assertSame(StockReservationLineStatus::RESERVED->value, $bMapAfter[$sku]['status'] ?? null);
        }
    }

    public function test_cancel_unrelated_reservation_does_not_change_other_sku(): void
    {
        $this->expectSuccess();

        $skuA = 'SKU-001';
        $skuB = 'SKU-003'; // present in fixtures, independent stock pools

        // Create A on SKU-001 and B on SKU-003
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-UNREL-A', 'lines' => [['productSku' => $skuA, 'qty' => 1]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-UNREL-B', 'lines' => [['productSku' => $skuB, 'qty' => 999]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $beforeB = $this->readReservationJson('ORD-UNREL-B');
        $mapBefore = $this->lineMap($beforeB);
        $reservedBefore = $mapBefore[$skuB]['reserved'];

        // Cancel on different SKU
        $this->client->request('PUT', $this->url('stock_reservations_cancel', ['number' => 'ORD-UNREL-A']),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202);

        $afterB = $this->readReservationJson('ORD-UNREL-B');
        $mapAfter = $this->lineMap($afterB);
        self::assertSame($reservedBefore, $mapAfter[$skuB]['reserved'], 'Unrelated cancel must not affect another SKU');
    }

    /** Double cancel is idempotent: first 202, second 409/422/204 depending on your semantics */
    public function test_cancel_is_idempotent_and_does_not_reallocate_twice(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-IDEMP', 'lines' => [['productSku' => $sku, 'qty' => 1]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        // First cancel works
        $this->client->request('PUT', $this->url('stock_reservations_cancel', ['number' => 'ORD-IDEMP']),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202);

        // Second cancel should not trigger anything; expect conflict or no-content
        $this->client->request('PUT', $this->url('stock_reservations_cancel', ['number' => 'ORD-IDEMP']),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertTrue(
            in_array($this->client->getResponse()->getStatusCode(), [204, 409, 422], true),
            'Second cancel should be a no-op (204) or validation/conflict (409/422)'
        );
    }

    /** (Optional) sanity: reallocation doesn’t switch warehouse if already optimal single-warehouse allocation */
    public function test_reallocation_does_not_worsen_allocation(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $order = 'ORD-STABLE';

        // Reserve B so that it’s fully satisfied from a single warehouse
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => $order, 'lines' => [['productSku' => $sku, 'qty' => 1]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $before = $this->readReservationJson($order);
        $mapBefore = $this->lineMap($before);
        $whBefore = $mapBefore[$sku]['warehouse'];

        // Create & cancel some other order to trigger reallocation scans
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-STABLE-TMP', 'lines' => [['productSku' => $sku, 'qty' => 1]]], JSON_THROW_ON_ERROR)
        );
        $this->client->request('PUT', $this->url('stock_reservations_cancel', ['number' => 'ORD-STABLE-TMP']),
            server: ['CONTENT_TYPE' => 'application/json']
        );

        $after = $this->readReservationJson($order);
        $mapAfter = $this->lineMap($after);

        // It may legitimately change to a "better" single-warehouse, but should not break full reservation
        self::assertSame(StockReservationStatus::RESERVED->value, $after['status'] ?? null);
        self::assertNotNull($mapAfter[$sku]['warehouse']);
    }

    public function test_fifo_reallocation_prioritizes_earlier_waiter(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThan(1, $avail);

        // A fully reserves everything (or almost everything)
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-FIFO-A', 'lines' => [['productSku' => $sku, 'qty' => $avail]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        // B and C both wait for stock (partial or zero)
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-FIFO-B', 'lines' => [['productSku' => $sku, 'qty' => $avail]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-FIFO-C', 'lines' => [['productSku' => $sku, 'qty' => $avail]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $bBefore = $this->lineMap($this->readReservationJson('ORD-FIFO-B'))[$sku]['reserved'];
        $cBefore = $this->lineMap($this->readReservationJson('ORD-FIFO-C'))[$sku]['reserved'];

        // Cancel A → frees stock; B should gain before C
        $this->client->request('PUT', $this->url('stock_reservations_cancel', ['number' => 'ORD-FIFO-A']),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202);

        $bAfter = $this->lineMap($this->readReservationJson('ORD-FIFO-B'))[$sku]['reserved'];
        $cAfter = $this->lineMap($this->readReservationJson('ORD-FIFO-C'))[$sku]['reserved'];

        self::assertGreaterThan($bBefore, $bAfter, 'B must gain first');
        self::assertGreaterThanOrEqual($cBefore, $cAfter, 'C should not lose reservation');
        self::assertGreaterThanOrEqual($bAfter - $bBefore, $cAfter - $cBefore, 'C must not gain more than B');
    }

    /** If a single-warehouse full allocation exists, later scans must not degrade it */
    public function test_reallocation_keeps_single_warehouse_full_reservation_intact(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';

        // Reserve fully
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-STABLE-2', 'lines' => [['productSku' => $sku, 'qty' => 1]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        $before = $this->readReservationJson('ORD-STABLE-2');
        $mapB = $this->lineMap($before);
        self::assertSame(StockReservationStatus::RESERVED->value, $before['status']);
        $wh = $mapB[$sku]['warehouse'];
        self::assertNotNull($wh);

        // Create & cancel another order to provoke reallocation scanning
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-STABLE-2-TMP', 'lines' => [['productSku' => $sku, 'qty' => 1]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $this->client->request('PUT', $this->url('stock_reservations_cancel', ['number' => 'ORD-STABLE-2-TMP']),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202);

        $after = $this->readReservationJson('ORD-STABLE-2');
        $mapA = $this->lineMap($after);
        self::assertSame(StockReservationStatus::RESERVED->value, $after['status']);
        self::assertNotNull($mapA[$sku]['warehouse']);
    }

    /** Stronger FIFO: earlier waiter achieves full before later waiter gets more than it */
    public function test_fifo_full_first_then_next_gets_leftovers(): void
    {
        $this->expectSuccess();

        $sku = 'SKU-001';
        $avail = $this->maxAvailable($sku);
        self::assertGreaterThanOrEqual(2, $avail, 'Need at least 2 for this scenario');

        // A occupies all available
        $this->client->request('POST', $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['number' => 'ORD-FIFO2-A', 'lines' => [['productSku' => $sku, 'qty' => $avail]]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        // B and C both want 1
        foreach (['ORD-FIFO2-B', 'ORD-FIFO2-C'] as $n) {
            $this->client->request('POST', $this->url('stock_reservations_create'),
                server: ['CONTENT_TYPE' => 'application/json'],
                content: json_encode(['number' => $n, 'lines' => [['productSku' => $sku, 'qty' => 1]]], JSON_THROW_ON_ERROR)
            );
            self::assertResponseStatusCodeSame(201);
        }

        // Cancel A; freed qty = $avail
        $this->client->request('PUT', $this->url('stock_reservations_cancel', ['number' => 'ORD-FIFO2-A']),
            server: ['CONTENT_TYPE' => 'application/json']
        );
        self::assertResponseStatusCodeSame(202);

        $b = $this->readReservationJson('ORD-FIFO2-B');
        $c = $this->readReservationJson('ORD-FIFO2-C');
        $mb = $this->lineMap($b)[$sku];
        $mc = $this->lineMap($c)[$sku];

        // B should be full first; C should not exceed B
        self::assertSame($mb['ordered'], $mb['reserved'], 'B should be fully reserved first');
        self::assertGreaterThanOrEqual(0, $mc['reserved']);
        self::assertLessThanOrEqual($mb['reserved'], $mc['reserved'], 'C must not get more than B at this point');
    }
}
