<?php
declare(strict_types=1);

namespace App\Orders\Tests;

use App\Orders\Entity\Order;
use App\Orders\Messages\OrderCreatedMessage;
use App\Orders\Repository\OrderRepository;
use App\Orders\Tests\DataFixtures\OrderTestFixture;
use App\Shared\Tests\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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
        $orderNumber = 'ORD-TEST-001';
        $this->requireOrder($orderNumber);

        $this->client->request('GET', $this->url('orders_read', ['number' => $orderNumber]));

        self::assertResponseStatusCodeSame(200);

        $json = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame($orderNumber, $json['number'] ?? null);

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

        $orderNumber = 'ORD-NEW-001';
        $payload = [
            'number' => $orderNumber,
            'lines'  => [
                ['productSku' => 'SKU-001', 'qty' => 2],
                ['productSku' => 'SKU-002', 'qty' => 1],
            ],
        ];

        $createUrl = $this->url('orders_create');
        $readUrl   = $this->url('orders_read', ['number' => $orderNumber]);

        $this->client->request(
            'POST',
            $createUrl,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertSame($readUrl, parse_url($location, PHP_URL_PATH));

        $saved = $this->repo->findOneByNumber($orderNumber);
        self::assertInstanceOf(\App\Orders\Entity\Order::class, $saved);
        self::assertSame($orderNumber, $saved->getNumber());
        self::assertCount(2, $saved->getLines()); // two distinct SKUs

        // Status should be PENDING at creation time (order and lines)
        self::assertSame('PENDING', $saved->getStatus());
        foreach ($saved->getLines() as $line) {
            self::assertSame('PENDING', $line->getStatus()->value);
        }
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

        $orderNumber = 'ORD-NEW-999';
        $payload = [
            'number' => $orderNumber,
            'lines'  => [
                ['productSku' => 'SKU-001', 'qty' => 1],
                ['productSku' => 'SKU-001', 'qty' => 2], // duplicate SKU in payload
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

    public function test_create_dispatches_order_created_message(): void
    {
        $this->expectSuccess();

        $orderNumber = 'ORD-MSG-TEST-001';
        $payload = [
            'number' => $orderNumber,
            'lines'  => [
                ['productSku' => 'SKU-001', 'qty' => 2],
                ['productSku' => 'SKU-002', 'qty' => 1],
            ],
        ];

        // Get the transport before creating the order
        $transport = $this->c->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        // Clear any existing messages
        $transport->reset();

        $this->client->request(
            'POST',
            $this->url('orders_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        // Verify order was created
        $order = $this->repo->findOneByNumber($orderNumber);
        self::assertInstanceOf(Order::class, $order);

        // Verify message was dispatched
        $sent = $transport->getSent();
        self::assertCount(1, $sent, 'Exactly one OrderCreatedMessage should be dispatched');

        $envelope = $sent[0];
        $message = $envelope->getMessage();
        
        self::assertInstanceOf(OrderCreatedMessage::class, $message);
        self::assertSame($orderNumber, $message->orderNumber);
        self::assertCount(2, $message->lines);
        
        // Verify message content
        self::assertSame('PENDING', $message->status, 'Order status should be PENDING when created');
        
        $line1 = $message->lines[0];
        $line2 = $message->lines[1];
        
        self::assertSame('SKU-001', $line1['productSku']);
        self::assertSame(2, $line1['qtyOrdered']);
        self::assertSame('SKU-002', $line2['productSku']);
        self::assertSame(1, $line2['qtyOrdered']);
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
