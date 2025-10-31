<?php
declare(strict_types=1);

namespace App\Orders\Tests;

use App\Orders\Entity\Order;
use App\Orders\Repository\OrderRepository;
use App\Orders\Tests\DataFixtures\OrderTestFixture;
use App\Shared\Tests\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    private OrderRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->c->get(OrderRepository::class);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            OrderTestFixture::class,
        ];
    }

    public function test_demo_data_fixture_loaded(): void
    {
        $this->expectSuccess();

        $existing = $this->repo->findOneByNumber('ORD-TEST-001');
        self::assertInstanceOf(Order::class, $existing);
    }

    public function test_read_payload_includes_lines_without_backref(): void
    {
        $this->expectSuccess();
        $number = 'ORD-TEST-001';
        $this->requireOrder($number);

        $this->client->request('GET', $this->url('orders_read', ['number' => $number]));

        self::assertResponseStatusCodeSame(200);

        $json = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame($number, $json['number'] ?? null);

        // Lines should be present and an array (fixture has 2)
        self::assertArrayHasKey('lines', $json);
        self::assertIsArray($json['lines']);
        self::assertCount(2, $json['lines']);

        $first = $json['lines'][0];
        self::assertIsArray($first);
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('productSku', $first);
        self::assertArrayHasKey('qtyOrdered', $first);
        self::assertArrayHasKey('qtyShipped', $first);
        self::assertArrayHasKey('qtyReserved', $first);
        self::assertArrayNotHasKey('order', $first, 'Back-reference must be ignored to avoid recursion');
    }

    public function test_read_returns_404_for_missing_order(): void
    {
        $this->expectError();

        $this->client->request('GET', $this->url('orders_read', ['number' => 'ORD-NOPE-404']));

        self::assertResponseStatusCodeSame(404);

        $payload = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('Order not found', $payload['message'] ?? null);
    }

    public function test_create_returns_201_persists_and_sets_location(): void
    {
        $this->expectSuccess();

        $number = 'ORD-NEW-001';
        $payload = [
            'number' => $number,
            'lines'  => [
                ['productSku' => 'SKU-001', 'qty' => 2],
                ['productSku' => 'SKU-002', 'qty' => 1],
            ],
        ];

        $createUrl = $this->url('orders_create');
        $readUrl   = $this->url('orders_read', ['number' => $number]);

        $this->client->request(
            'POST',
            $createUrl,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertSame(
            $this->url('orders_read', ['number' => $number]),           // relative path
            parse_url($location, PHP_URL_PATH)                          // actual path from absolute URL
        );

        $saved = $this->repo->findOneByNumber($number);
        self::assertInstanceOf(\App\Orders\Entity\Order::class, $saved);
        self::assertSame($number, $saved->getNumber());
        self::assertCount(2, $saved->getLines()); // two distinct SKUs
    }

    public function test_create_returns_409_on_duplicate_number(): void
    {
        $this->expectError(); // we expect a 4xx response

        $payload = [
            'number' => 'ORD-TEST-001', // already seeded by OrderTestFixture
            'lines'  => [
                ['productSku' => 'SKU-001', 'qty' => 1],
            ],
        ];

        $this->client->request(
            'POST',
            $this->url('orders_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(409);
        self::assertJson($this->client->getResponse()->getContent());

        // Ensure the original order still exists and wasn't modified
        $saved = $this->repo->findOneByNumber('ORD-TEST-001');
        self::assertInstanceOf(\App\Orders\Entity\Order::class, $saved);
    }

    public function test_create_does_not_allow_duplicate_sku(): void
    {
        $this->expectError();

        $number = 'ORD-NEW-999';
        $payload = [
            'number' => $number,
            'lines'  => [
                ['productSku' => 'SKU-001', 'qty' => 1],
                ['productSku' => 'SKU-001', 'qty' => 2], // same SKU, different case
            ],
        ];

        $this->client->request(
            'POST',
            $this->url('orders_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(409);
    }


    /**
     * Helper for making sure fixture data exists
     * @param string $number
     * @return void
     */
    private function requireOrder(string $number): void
    {
        self::assertNotNull(
            $this->repo->findOneByNumber($number),
            "Fixture missing Order $number"
        );
    }
}
