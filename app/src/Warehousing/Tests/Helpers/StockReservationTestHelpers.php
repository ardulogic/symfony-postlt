<?php

namespace App\Warehousing\Tests\Helpers;

use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\StockReservationRepository;

trait StockReservationTestHelpers
{
    protected StockItemRepository $stockRepo;
    protected StockReservationRepository $resRepo;

    protected function setUpRepositories(): void
    {
        $this->resRepo = $this->c->get(StockReservationRepository::class);
        $this->stockRepo = $this->c->get(StockItemRepository::class);
    }

    /**
     * Read reservation JSON by number via API
     */
    protected function readReservation(string $number): array
    {
        $this->client->request('GET', $this->url('stock_reservations_read', ['number' => $number]));
        self::assertResponseStatusCodeSame(200);

        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Map reservation lines by SKU: [sku => [ordered, reserved, warehouse, status]]
     */
    protected function lineMap(array $reservationJson): array
    {
        $map = [];
        foreach ($reservationJson['lines'] ?? [] as $line) {
            $map[$line['productSku']] = [
                'ordered' => (int)$line['orderedQty'],
                'reserved' => (int)$line['reservedQty'],
                'warehouse' => $line['warehouse']['code'] ?? null,
                'status' => $line['status'] ?? null,
            ];
        }
        return $map;
    }

    /**
     * Get maximum available quantity across all warehouses for a SKU
     */
    protected function maxAvailable(string $sku): int
    {
        $items = $this->stockRepo->getBySkusSortedByDescAvailability([$sku]);
        $max = 0;

        foreach ($items as $stockItem) {
            if ($stockItem->getProductSku() === $sku) {
                $max = max($max, $stockItem->getAvailableQty());
            }
        }

        return $max;
    }

    /**
     * Get available quantity at a specific warehouse
     */
    protected function availableAt(string $sku, string $warehouseCode): int
    {
        $items = $this->stockRepo->getBySkusSortedByDescAvailability([$sku]);

        foreach ($items as $stockItem) {
            if ($stockItem->getProductSku() === $sku
                && $stockItem->getWarehouse()->getCode() === $warehouseCode) {
                return $stockItem->getAvailableQty();
            }
        }

        return 0;
    }

    /**
     * Get on-hand quantity at a specific warehouse (for shipping tests)
     */
    protected function onHandAt(string $warehouseCode, string $sku): int
    {
        $stockItem = $this->stockRepo->findOneByWarehouseCodeAndSku($warehouseCode, $sku);

        return $stockItem ? $stockItem->getOnHandQty() : 0;
    }

    /**
     * Create a reservation via API and return the response
     */
    protected function createReservation(string $number, string $sku, int $qty): array
    {
        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'number' => $number,
                'lines' => [['productSku' => $sku, 'qty' => $qty]],
            ], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        return $this->readReservation($number);
    }

    /**
     * Cancel a reservation via API and return status code
     */
    protected function cancelReservation(string $number): int
    {
        $this->client->request(
            'PUT',
            $this->url('stock_reservations_cancel', ['number' => $number]),
            server: ['CONTENT_TYPE' => 'application/json']
        );

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * Ship a reservation via API and return status code
     */
    protected function shipReservation(string $number): int
    {
        $this->client->request(
            'POST',
            $this->url('stock_reservations_ship', ['number' => $number]),
            server: ['CONTENT_TYPE' => 'application/json']
        );

        return $this->client->getResponse()->getStatusCode();
    }
}

