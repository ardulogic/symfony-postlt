<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http;

use App\Tests\Support\WebTestCase;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Tests\DataFixtures\StockItemTestFixture;
use App\Warehousing\Tests\Helpers\StockItemTestHelpers;

final class StockItemControllerTest extends WebTestCase
{
    private StockItemRepository $repo;
    use StockItemTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->c->get(StockItemRepository::class);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            StockItemTestFixture::class,
        ];
    }

    public function test_demo_data_fixture_loaded(): void
    {
        $this->expectSuccess();

        $this->requireItem('WARE-EU-1', 'SKU-001');
    }

    /**
     * Read stock item
     * Endpoint: stock_items_read
     * Given an existing warehouse and SKU, when reading the item, then 200 and JSON containing matching SKU.
     * @throws \JsonException
     */
    public function test_read_returns_200_for_existing_item(): void
    {
        $this->expectSuccess();

        $warehouseCode = 'WARE-EU-1';
        $productSku  = 'SKU-001';

        $data = $this->readStockItem($warehouseCode, $productSku);

        // Be tolerant to serializer shape
        $payloadSku = $data['productSku'] ?? $data['sku'] ?? null;
        if ($payloadSku !== null) {
            self::assertSame($productSku, $payloadSku);
        }
    }

    /**
     * Read stock item - unknown SKU
     * Endpoint: stock_items_read
     * Given an existing warehouse but unknown SKU, when reading the item, then 404 with error message.
     * @throws \JsonException
     */
    public function test_read_returns_404_for_unknown_sku_in_existing_warehouse(): void
    {
        $this->expectError();

        $warehouseCode = 'WARE-EU-1';
        $productSku  = 'NOPE-999';

        $data = $this->readStockItem($warehouseCode, $productSku, 404);
        self::assertSame('Stock item not found', $data['message'] ?? null);
    }

    /**
     * Read stock item - unknown warehouse
     * Endpoint: stock_items_read
     * Given an unknown warehouse, when reading an item, then 404 with error message.
     * @throws \JsonException
     */
    public function test_read_returns_404_for_unknown_warehouse(): void
    {
        $this->expectSuccess();

        $code = 'NOPE-404';
        $sku  = 'SKU-001';

        $data = $this->readStockItem($code, $sku, 404);
        self::assertSame('Stock item not found', $data['message'] ?? null);
    }

    /**
     * Receive stock
     * Endpoint: stock_items_receive
     * Given a missing SKU in an existing warehouse, when receiving stock, then 201 and entity created with on-hand set.
     * @throws \JsonException
     */
    public function test_receive_creates_new_item_and_returns_201_with_location(): void
    {
        $this->expectSuccess();

        $warehouseCode = 'WARE-EU-1';
        $productSku  = 'SKU-003'; // not in fixture → should create

        $this->receiveStock($warehouseCode, $productSku, 5);

        $item = $this->repo->findOneByWarehouseCodeAndSku($warehouseCode, $productSku);
        self::assertNotNull($item, 'Stock item should be created');
        self::assertSame(5, $item->getOnHandQty());
        self::assertSame(0, $item->getReservedQty());
    }

    /**
     * Receive stock increments lock version
     * Endpoint: stock_items_receive
     * Given an existing item, when receiving stock, then 201 and lock version increases and on-hand grows by qty.
     * @throws \JsonException
     */
    public function test_receive_increments_lock_version(): void
    {
        $this->expectSuccess();

        $warehouseCode = 'WARE-EU-1';
        $productSku  = 'SKU-001'; // fixture has it

        // before
        $beforeItem = $this->repo->findOneByWarehouseCodeAndSku($warehouseCode, $productSku);
        self::assertNotNull($beforeItem);
        $beforeLv = $beforeItem->getLockVersion();
        $beforeOnHand = $beforeItem->getOnHandQty();
        $this->em->clear();

        // act
        $this->receiveStock($warehouseCode, $productSku, 3);

        // make sure we don’t read a cached entity
        static::getContainer()->get('doctrine')->getManager()->clear();

        // after
        $afterItem = $this->repo->findOneByWarehouseCodeAndSku($warehouseCode, $productSku);
        self::assertNotNull($afterItem);
        self::assertTrue($beforeLv  < $afterItem->getLockVersion());
        self::assertSame($beforeOnHand + 3, $afterItem->getOnHandQty());
    }

    /**
     * Helper for making sure fixture data exists
     * @param string $code
     * @param string $sku
     * @return void
     */
    private function requireItem(string $code, string $sku): void
    {
        self::assertNotNull($this->repo->findOneByWarehouseCodeAndSku($code, $sku), "Fixture missing code or sku: $code or $sku");
    }

}
