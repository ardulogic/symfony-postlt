<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http;

use App\Shared\Tests\WebTestCase;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\StockReservationRepository;
use App\Warehousing\Tests\DataFixtures\StockReservationEmptyTestFixture;

final class StockReservationShippingTest extends WebTestCase
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

    /** Ship: happy path — marks reservation shipped and decrements available stock. */
    public function test_ship_full_reservation_decrements_stock_and_marks_shipped(): void
    {
        $this->expectSuccess();

        $sku   = 'SKU-001';
        $avail = $this->getMaxQtyInSingleWarehouse($sku);
        self::assertGreaterThan(0, $avail, 'Fixture must provide available stock for SKU-001');

        $number = 'ORD-SHIP-OK';
        $qty   = 1;

        // Create a reservation that can be fully allocated
        $response = $this->createReservation($number, $sku, $qty);
        $warehouseCode = $response['lines'][0]['warehouse']['code'];
        $beforeAvail = $this->getOnHandQtyAtWarehouse($warehouseCode, $sku);

        // Ship it
        $status = $this->shipReservation($number);
        self::assertContains($status, [202], 'Ship endpoint should return 202');

        $this->em->clear();
        $afterAvail = $this->getOnHandQtyAtWarehouse($warehouseCode, $sku);

        self::assertSame(
            $beforeAvail - $qty,
            $afterAvail,
            'Available stock must decrease by shipped quantity'
        );

        // Read back reservation and verify status
        $json = $this->readReservationJson($number);
        self::assertSame('shipped', strtolower((string)($json['status'] ?? '')), 'Reservation status should be SHIPPED');

        // Lines should be shipped; be tolerant about field naming
        self::assertIsArray($json['lines'] ?? null);
        foreach ($json['lines'] as $line) {
            if (($line['productSku'] ?? null) === $sku) {
                self::assertSame('shipped', strtolower((string)($line['status'] ?? '')), 'Line status should be SHIPPED');
            }
        }
    }

    /** Ship: 404 when reservation number does not exist. */
    public function test_ship_unknown_reservation_returns_404(): void
    {
        $this->expectError();

        $status = $this->shipReservation('ORD-DOES-NOT-EXIST');
        self::assertSame(404, $status);
    }

    /** Ship: cannot ship a cancelled reservation (expect 409/422). */
    public function test_ship_after_cancel_is_rejected(): void
    {
        $this->expectError();

        $sku   = 'SKU-001';
        $avail = $this->getMaxQtyInSingleWarehouse($sku);
        self::assertGreaterThan(0, $avail);

        $number = 'ORD-SHIP-CANCELLED';
        $qty   = 1;

        $this->createReservation($number, $sku, $qty);

        // Cancel first
        $this->client->request('PUT', $this->url('stock_reservations_cancel', ['number' => $number]));
        $response = $this->client->getResponse();
        $cancelStatus = $response->getStatusCode();
        self::assertSame($cancelStatus, 202);

        // Try to ship afterwards
        $shipStatus = $this->shipReservation($number);
        self::assertSame($shipStatus, 409, 'Shipping a cancelled reservation should be rejected');

        // Ensure it remains cancelled
        $json = $this->readReservationJson($number);
        self::assertSame(StockReservationStatus::CANCELED->value, (string)($json['status'] ?? ''));
    }

    /** Ship: second attempt should not double-decrement stock (idempotency/guard). */
    public function test_ship_twice_does_not_decrement_stock_twice(): void
    {
        $this->expectSuccess();

        $sku   = 'SKU-001';
        $avail = $this->getMaxQtyInSingleWarehouse($sku);
        self::assertGreaterThan(0, $avail);

        $number = 'ORD-SHIP-TWICE';
        $qty   = min(2, $avail); // keep it small but >0

        $response = $this->createReservation($number, $sku, $qty);
        $warehouseCode = $response['lines'][0]['warehouse']['code'];
        $onHandBeforeShipment = $this->getOnHandQtyAtWarehouse($warehouseCode, $sku);

        $shipStatus = $this->shipReservation($number);
        self::assertSame($shipStatus, 202);

        $this->em->clear();

        $qtyAfterFirstShipment = $this->getOnHandQtyAtWarehouse($warehouseCode, $sku);
        self::assertSame($onHandBeforeShipment - $qty, $qtyAfterFirstShipment);

        // Second attempt (should not allow double ship)
        $shipStatus2 = $this->shipReservation($number);
        self::assertSame($shipStatus2, 409);

        $this->em->clear();

        $qtyAfterSecondShipment = $this->getOnHandQtyAtWarehouse($warehouseCode, $sku);
        self::assertSame($qtyAfterFirstShipment, $qtyAfterSecondShipment, 'Second ship must not change available stock again');

        $json = $this->readReservationJson($number);
        self::assertSame(StockReservationStatus::SHIPPED->value, (string)($json['status'] ?? ''));

    }

    // -----------------------
    // Helpers (mirror your style)
    // -----------------------

    /** Create reservation via API and assert 201. */
    private function createReservation(string $number, string $sku, int $qty): array
    {
        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'number' => $number,
                'lines'  => [['productSku' => $sku, 'qty' => $qty]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        return $this->readReservationJson($number);
    }

    /** POST /ship and return status code. */
    private function shipReservation(string $number): int
    {
        $this->client->request(
            'POST',
            $this->url('stock_reservations_ship', ['number' => $number]),
            server: ['CONTENT_TYPE' => 'application/json']
        );

        return $this->client->getResponse()->getStatusCode();
    }

    /** Read reservation JSON by number. */
    private function readReservationJson(string $number): array
    {
        $this->client->request('GET', $this->url('stock_reservations_read', ['number' => $number]));
        self::assertResponseIsSuccessful();

        /** @var array $json */
        $json = json_decode((string)$this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    private function getMaxQtyInSingleWarehouse(string $sku): int
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

    private function getOnHandQtyAtWarehouse(string $wareCode, string $sku): int
    {
        $stockItem =  $this->stockRepo->findOneByWarehouseCodeAndSku($wareCode, $sku);

        if ($stockItem) {
            return $stockItem->getOnHandQty();
        }

        return 0;
    }

}
