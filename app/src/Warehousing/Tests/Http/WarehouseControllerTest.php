<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http;

use App\Shared\Tests\WebTestCase;
use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Repository\WarehouseRepository;
use App\Warehousing\Tests\DataFixtures\WarehouseTestFixture;
use JsonException;

final class WarehouseControllerTest extends WebTestCase
{
    private WarehouseRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->c->get(WarehouseRepository::class);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            WarehouseTestFixture::class,
        ];
    }

    public function test_demo_data_fixture_loaded(): void
    {
        $this->expectSuccess();

        $existing = $this->repo->findOneByCode('WARE-EU-1');
        self::assertInstanceOf(Warehouse::class, $existing);
    }

    /**
     * @throws JsonException
     */
    public function test_create_returns_201_and_persists(): void
    {
        $this->expectSuccess();

        $payload = ['code' => 'WARE-US-1-NEW', 'name' => 'New Warehouse'];
        $this->client->request('POST',
            $this->url('warehouses_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $saved = $this->repo->findOneByCode('WARE-US-1-NEW');
        self::assertInstanceOf(Warehouse::class, $saved);
        self::assertSame('New Warehouse', $saved->getName());
        self::assertSame('WARE-US-1-NEW', $saved->getCode());
    }

    /**
     * @throws JsonException
     */
    public function test_create_returns_409_on_duplicate_code(): void
    {
        $this->expectError();
        $this->requireCode('WARE-EU-1');

        $payload = ['code' => 'WARE-EU-1', 'name' => 'Another Name'];
        $this->client->request('POST',
            $this->url('warehouses_create'),
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

        $payload = ['code' => '1233@#$#@$!', 'name' => ''];
        $this->client->request('POST',
            $this->url('warehouses_create'),
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
        $this->requireCode('WARE-EU-1');

        $payload = ['name' => 'Renamed Warehouse'];
        $this->client->request('PUT',
            $this->url('warehouses_update', ['code' => 'WARE-EU-1']),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(200);

        $saved = $this->repo->findOneByCode('WARE-EU-1');
        self::assertInstanceOf(Warehouse::class, $saved);
        self::assertSame('Renamed Warehouse', $saved->getName());
        self::assertSame('WARE-EU-1', $saved->getCode());
    }

    /**
     * @throws JsonException
     */
    public function test_update_returns_409_when_changing_code_to_existing(): void
    {
        $this->expectError();
        $this->requireCode('WARE-EU-1');

        // Precondition: fixtures provide WARE-EU-1 to SKU-003
        $payload = ['code' => 'WARE-EU-2'];

        $this->client->request('PUT',
            $this->url('warehouses_update', ['code' => 'WARE-EU-1']),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(409);
        self::assertJson($this->client->getResponse()->getContent());

        // Ensure nothing changed
        $p1After = $this->repo->findOneByCode('WARE-EU-1');
        self::assertSame('WARE-EU-1', $p1After->getCode());

        $p2After = $this->repo->findOneByCode('WARE-EU-2');
        self::assertSame('WARE-EU-2', $p2After->getCode());
    }

    public function test_delete_returns_204_and_removes_entity(): void
    {
        $this->expectSuccess();
        $this->requireCode('WARE-EU-1');

        // Act
        $this->client->request('DELETE',
            $this->url('warehouses_delete', ['code' => 'WARE-EU-1']));

        // Assert
        self::assertResponseStatusCodeSame(204);
        self::assertSame('', $this->client->getResponse()->getContent(), '204 should have empty body');

        // Ensure it’s gone and SKU is freed
        $deleted = $this->repo->findOneByCode('WARE-EU-1');
        self::assertNull($deleted);
    }

    public function test_read_returns_200_with_warehouse(): void
    {
        $this->expectSuccess();
        $this->requireCode('WARE-EU-1');

        $this->client->request('GET', $this->url('warehouses_read', ['code' => 'WARE-EU-1']));

        self::assertResponseStatusCodeSame(200);

        $json = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($json);
        self::assertSame('WARE-EU-1', $json['code'] ?? null);
        self::assertArrayHasKey('id', $json);
        self::assertArrayHasKey('name', $json);
    }

    public function test_read_returns_404_for_missing_warehouse(): void
    {
        $this->expectError();

        $this->client->request('GET', $this->url('warehouses_read', ['code' => 'WARE-NOPE']));
        self::assertResponseStatusCodeSame(404);
        self::assertJson($this->client->getResponse()->getContent());
    }

    public function test_list_returns_200_with_meta_and_data(): void
    {
        $this->expectSuccess();

        // Request page=1, per_page=1 to exercise pagination math
        $this->client->request('GET', $this->url('warehouses_list', [
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
        self::assertSame(2, $payload['meta']['total']);       // fixture seeds WARE-EU-1, WARE-EU-2
        self::assertSame(2, $payload['meta']['total_pages']); // ceil(2 / 1) = 2

        // Data assertions
        self::assertIsArray($payload['data']);
        self::assertCount(1, $payload['data']);               // per_page=1
        self::assertArrayHasKey('code', $payload['data'][0]);
        self::assertArrayHasKey('name', $payload['data'][0]);
        self::assertArrayHasKey('id', $payload['data'][0]);
    }

    public function test_list_second_page_contains_remaining_items(): void
    {
        $this->expectSuccess();

        // Page 2 with per_page=1 should return exactly one item (the 2nd warehouse)
        $this->client->request('GET', $this->url('warehouses_list', [
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
     * @param string $code
     * @return void
     */
    private function requireCode(string $code): void
    {
        self::assertNotNull($this->repo->findOneByCode($code), "Fixture missing code: $code");
    }

}
