<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http;

use App\Shared\Tests\WebTestCase;
use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Tests\DataFixtures\StockReservationTestFixture;
use App\Warehousing\Repository\StockReservationRepository;

final class StockReservationControllerTest extends WebTestCase
{
    private StockReservationRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->c->get(StockReservationRepository::class);
    }

    protected function getRequiredFixtures(): array
    {
        return [
            StockReservationTestFixture::class,
        ];
    }

    public function test_read_payload_has_correct_structure(): void
    {
        $this->expectSuccess();

        $number = 'ORD-TEST-001';
        $this->requireReservation($number);

        $this->client->request('GET', $this->url('stock_reservations_read', ['number' => $number]));

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
        self::assertArrayHasKey('orderedQty', $first);
        self::assertArrayHasKey('shippedQty', $first);
        self::assertArrayHasKey('reservedQty', $first);
        self::assertArrayHasKey('warehouse', $first);
        self::assertArrayHasKey('id', $first['warehouse']);
        self::assertArrayHasKey('code', $first['warehouse']);
        self::assertArrayHasKey('name', $first['warehouse']);
        self::assertArrayNotHasKey('reservation', $first, 'Back-reference must be ignored to avoid recursion');
    }

    public function test_read_returns_404_for_missing_stock_reservation(): void
    {
        $this->expectError();

        $this->client->request('GET', $this->url('stock_reservations_read', ['number' => 'ORD-NOPE-404']));

        self::assertResponseStatusCodeSame(404);

        $payload = json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
    }

    public function test_create_returns_201_persists_and_sets_location(): void
    {
        $this->expectSuccess();

        $number = 'ORD-NEW-001';
        $payload = [
            'number' => $number,
            'lines' => [
                ['productSku' => 'SKU-001', 'qty' => 2],
                ['productSku' => 'SKU-002', 'qty' => 1],
            ],
        ];

        $createUrl = $this->url('stock_reservations_create');
        $readUrl = $this->url('stock_reservations_read', ['number' => $number]);

        $this->client->request(
            'POST',
            $createUrl,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(201);

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertSame($readUrl, parse_url($location, PHP_URL_PATH));

        $saved = $this->repo->findOneByNumber($number);
        self::assertInstanceOf(StockReservation::class, $saved);
        self::assertSame($number, $saved->getNumber());
        self::assertCount(2, $saved->getLines()); // two distinct SKUs
    }

    public function test_create_returns_409_on_duplicate_number(): void
    {
        $this->expectError(); // we expect a 4xx response

        $payload = [
            'number' => 'ORD-TEST-001', // already seeded by OrderTestFixture
            'lines' => [
                ['productSku' => 'SKU-001', 'qty' => 1],
            ],
        ];

        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        self::assertResponseStatusCodeSame(409);
        self::assertJson($this->client->getResponse()->getContent());

        // Ensure the original stock_reservation still exists and wasn't modified
        $saved = $this->repo->findOneByNumber('ORD-TEST-001');
        self::assertInstanceOf(StockReservation::class, $saved);
    }

    public function test_create_does_not_allow_duplicate_sku(): void
    {
        $this->expectError();

        $number = 'ORD-NEW-999';
        $payload = [
            'number' => $number,
            'lines' => [
                ['productSku' => 'SKU-001', 'qty' => 1],
                ['productSku' => 'SKU-001', 'qty' => 2], // same SKU, different case
            ],
        ];

        $this->client->request(
            'POST',
            $this->url('stock_reservations_create'),
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
    private function requireReservation(string $number): void
    {
        self::assertNotNull(
            $this->repo->findOneByNumber($number),
            "Fixture missing Order $number"
        );
    }
}
