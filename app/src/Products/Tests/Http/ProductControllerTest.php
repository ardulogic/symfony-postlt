<?php
declare(strict_types=1);

namespace App\Products\Tests\Http;

use App\Products\Entity\Product;
use App\Products\Repository\ProductRepository;
use App\Products\Tests\DataFixtures\ProductTestFixture;
use App\Shared\Tests\WebTestCase;
use JsonException;

final class ProductControllerTest extends WebTestCase
{
    private ProductRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->c->get(ProductRepository::class);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            ProductTestFixture::class,
        ];
    }

    public function test_demo_data_fixture_loaded(): void
    {
        $this->expectSuccess();

        $existing = $this->repo->findOneBySku('SKU-001');
        self::assertInstanceOf(Product::class, $existing);
    }

    /**
     * @throws JsonException
     */
    public function test_create_returns_201_and_persists(): void
    {
        $this->expectSuccess();

        $payload = ['sku' => 'SKU-ABC_99', 'name' => 'New Product'];
        $this->client->request('POST',
            $this->url('products_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $saved = $this->repo->findOneBySku('SKU-ABC_99');
        self::assertInstanceOf(Product::class, $saved);
        self::assertSame('New Product', $saved->getName());
        self::assertSame('SKU-ABC_99', $saved->getSku());
    }

    /**
     * @throws JsonException
     */
    public function test_create_returns_409_on_duplicate_sku(): void
    {
        $this->expectError();
        $this->requireSku('SKU-001');

        $payload = ['sku' => 'SKU-001', 'name' => 'Another Name'];
        $this->client->request('POST',
            $this->url('products_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(409);
        self::assertJson($this->client->getResponse()->getContent());
    }

    /**
     * @throws JsonException
     */
    public function test_create_returns_422_on_validation_errors(): void
    {
        $this->expectError();

        $payload = ['sku' => '1233@#$#@$!', 'name' => ''];
        $this->client->request('POST',
            $this->url('products_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(422);
        self::assertJson($this->client->getResponse()->getContent());
    }

    /**
     * @throws JsonException
     */
    public function test_update_returns_200_and_persists_changes(): void
    {
        $this->expectSuccess();
        $this->requireSku('SKU-001');

        $payload = ['name' => 'Renamed Product'];
        $this->client->request('PUT',
            $this->url('products_update', ['sku' => 'SKU-001']),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(200);

        $saved = $this->repo->findOneBySku('SKU-001');
        self::assertInstanceOf(Product::class, $saved);
        self::assertSame('Renamed Product', $saved->getName());
        self::assertSame('SKU-001', $saved->getSku());
    }

    /**
     * @throws JsonException
     */
    public function test_update_returns_409_when_changing_sku_to_existing(): void
    {
        $this->expectError();
        $this->requireSku('SKU-001');
        $this->requireSku('SKU-002');

        // Precondition: fixtures provide SKU-001 and SKU-003
        $payload = ['sku' => 'SKU-002', 'name' => 'Collision Attempt'];

        $this->client->request('PUT',
            $this->url('products_update', ['sku' => 'SKU-001']),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(409);
        self::assertJson($this->client->getResponse()->getContent());

        // Ensure nothing changed
        $p1After = $this->repo->findOneBySku('SKU-001');
        $p3After = $this->repo->findOneBySku('SKU-002');

        self::assertSame('SKU-001', $p1After->getSku());
        self::assertSame('SKU-002', $p3After->getSku());
    }

    public function test_delete_returns_204_and_removes_entity(): void
    {
        $this->expectSuccess();
        $this->requireSku('SKU-001');

        // Act
        $this->client->request('DELETE',
            $this->url('products_delete', ['sku' => 'SKU-001']));

        // Assert
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent(), '204 should have empty body');

        // Ensure it’s gone and SKU is freed
        $deleted = $this->repo->findOneBySku('SKU-001');
        self::assertNull($deleted);
    }

    public function test_delete_returns_404_for_missing_product(): void
    {
        $this->expectError();

        // Ensure it truly doesn't exist
        self::assertNull($this->repo->findOneBySku('SKU-NOPE'));

        $this->client->request('DELETE',
            $this->url('products_delete', ['sku' => 'SKU-NOPE']));

        self::assertResponseStatusCodeSame(404);
        self::assertJson($this->client->getResponse()->getContent());
    }

    public function test_read_returns_200_with_product(): void
    {
        $this->expectSuccess();
        $this->requireSku('SKU-001');

        $this->client->request('GET', $this->url('products_read', ['sku' => 'SKU-001']));

        self::assertResponseStatusCodeSame(200);

        $json = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($json);
        self::assertSame('SKU-001', $json['sku'] ?? null);
        self::assertArrayHasKey('id', $json);
        self::assertArrayHasKey('name', $json);
    }

    public function test_read_returns_404_for_missing_product(): void
    {
        $this->expectError();

        $this->client->request('GET', $this->url('products_read', ['sku' => 'SKU-NOPE']));
        self::assertResponseStatusCodeSame(404);
        self::assertJson($this->client->getResponse()->getContent());
    }

    public function test_list_returns_200_with_meta_and_data(): void
    {
        $this->expectSuccess();

        // Request page=1, per_page=1 to exercise pagination math
        $this->client->request('GET', $this->url('products_list', [
            'page' => 1,
            'per_page' => 1,
        ]));

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('meta', $payload);

        // Meta assertions
        self::assertSame(1, $payload['meta']['page']);
        self::assertSame(1, $payload['meta']['per_page']);
        self::assertSame(2, $payload['meta']['total']);       // fixture seeds SKU-001, SKU-002
        self::assertSame(2, $payload['meta']['total_pages']); // ceil(2 / 1) = 2

        // Data assertions
        self::assertIsArray($payload['data']);
        self::assertCount(1, $payload['data']);               // per_page=1
        self::assertArrayHasKey('sku', $payload['data'][0]);
        self::assertArrayHasKey('name', $payload['data'][0]);
        self::assertArrayHasKey('id', $payload['data'][0]);
    }

    public function test_list_second_page_contains_remaining_items(): void
    {
        $this->expectSuccess();

        // Page 2 with per_page=1 should return exactly one item (the 2nd product)
        $this->client->request('GET', $this->url('products_list', [
            'page' => 2,
            'per_page' => 1,
        ]));

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('data', $payload);
        self::assertArrayHasKey('meta', $payload);

        self::assertSame(2, $payload['meta']['page']);
        self::assertSame(1, $payload['meta']['per_page']);
        self::assertSame(2, $payload['meta']['total']);
        self::assertSame(2, $payload['meta']['total_pages']);

        self::assertIsArray($payload['data']);
        self::assertCount(1, $payload['data']);
    }


    /**
     * Helper for making sure fixture data exists
     * @param string $sku
     * @return void
     */
    private function requireSku(string $sku): void
    {
        self::assertNotNull($this->repo->findOneBySku($sku), "Fixture missing SKU $sku");
    }

}
