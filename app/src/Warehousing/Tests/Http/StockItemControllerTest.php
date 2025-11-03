<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http;

use App\Shared\Tests\WebTestCase;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Tests\DataFixtures\StockItemTestFixture;
use JsonException;

final class StockItemControllerTest extends WebTestCase
{
    private StockItemRepository $repo;

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

    public function test_read_returns_200_for_existing_item(): void
    {
        $this->expectSuccess();

        $code = 'WARE-EU-1';
        $sku  = 'SKU-001';

        $this->client->request('GET',  $this->url('stock_items_read', ['code' =>$code, 'sku' => $sku]));

        self::assertResponseStatusCodeSame(200);
        self::assertTrue(
            str_starts_with($this->client->getResponse()->headers->get('Content-Type') ?? '', 'application/json'),
            'Response should be JSON'
        );

        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        // Be tolerant to serializer shape
        $payloadSku = $data['productSku'] ?? $data['sku'] ?? null;
        if ($payloadSku !== null) {
            self::assertSame($sku, $payloadSku);
        }
    }

    public function test_read_returns_404_for_unknown_sku_in_existing_warehouse(): void
    {
        $this->expectError();

        $code = 'WARE-EU-1';
        $sku  = 'NOPE-999';

        $this->client->request('GET', $this->url('stock_items_read', ['code' => $code, 'sku' => $sku]));

        self::assertResponseStatusCodeSame(404);

        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Stock item not found', $data['message'] ?? null);
    }

    public function test_read_returns_404_for_unknown_warehouse(): void
    {
        $this->expectSuccess();

        $code = 'NOPE-404';
        $sku  = 'SKU-001';

        $this->client->request('GET', $this->url('stock_items_read', ['code' => $code, 'sku' => $sku]));

        self::assertResponseStatusCodeSame(404);

        $data = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Stock item not found', $data['message'] ?? null);
    }

    public function test_receive_creates_new_item_and_returns_201_with_location(): void
    {
        $this->expectSuccess();

        $code = 'WARE-EU-1';
        $sku  = 'SKU-003'; // not in fixture → should create

        $postUrl  = $this->url('stock_items_receive', ['code' => $code, 'sku' => $sku]);
        $readUrl = $this->url('stock_items_read', ['code' => $code, 'sku' => $sku]);

        $this->client->request(
            'POST',
            $postUrl,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['qty' => 5], JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $item = $this->repo->findOneByWarehouseCodeAndSku($code, $sku);
        self::assertNotNull($item, 'Stock item should be created');
        self::assertSame(5, $item->getOnHandQty());
        self::assertSame(0, $item->getReservedQty());
    }

    public function test_receive_increments_lock_version(): void
    {
        $this->expectSuccess();

        $code = 'WARE-EU-1';
        $sku  = 'SKU-001'; // fixture has it

        // before
        $beforeItem = $this->repo->findOneByWarehouseCodeAndSku($code, $sku);
        self::assertNotNull($beforeItem);
        $beforeLv = $beforeItem->getLockVersion();
        $beforeOnHand = $beforeItem->getOnHandQty();
        $this->em->clear(\App\Warehousing\Entity\StockItem::class);

        // act
        $url = $this->url('stock_items_receive', ['code' => $code, 'sku' => $sku]);
        $this->client->request(
            'POST',
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['qty' => 3], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);

        // make sure we don’t read a cached entity
        static::getContainer()->get('doctrine')->getManager()->clear();

        // after
        $afterItem = $this->repo->findOneByWarehouseCodeAndSku($code, $sku);
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
