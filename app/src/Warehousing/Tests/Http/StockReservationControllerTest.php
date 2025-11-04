<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\Http;

use App\Tests\Support\WebTestCase;
use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Messages\StockReservationStatusChangedMessage;
use App\Warehousing\Repository\StockReservationRepository;
use App\Warehousing\Tests\DataFixtures\StockReservationTestFixture;
use App\Warehousing\Tests\Helpers\StockReservationTestHelpers;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class StockReservationControllerTest extends WebTestCase
{
    private StockReservationRepository $repo;
    use StockReservationTestHelpers;

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

        $reservationNumber = 'ORD-TEST-001';
        $this->requireReservation($reservationNumber);

		$json = $this->readReservation($reservationNumber);
        self::assertSame($reservationNumber, $json['number'] ?? null);

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

        $reservationNumber = 'ORD-NEW-001';
        $readUrl = $this->url('stock_reservations_read', ['number' => $reservationNumber]);

		$this->createReservationWithLines($reservationNumber, [
			['productSku' => 'SKU-001', 'qty' => 2],
			['productSku' => 'SKU-002', 'qty' => 1],
		]);

        $location = $this->client->getResponse()->headers->get('Location');
        self::assertSame($readUrl, parse_url($location, PHP_URL_PATH));

        $saved = $this->repo->findOneByNumber($reservationNumber);
        self::assertInstanceOf(StockReservation::class, $saved);
        self::assertSame($reservationNumber, $saved->getNumber());
        self::assertCount(2, $saved->getLines()); // two distinct SKUs
    }

    public function test_create_returns_409_on_duplicate_number(): void
    {
        $this->expectError(); // we expect a 4xx response

        $payload = [
            'number' => 'ORD-TEST-001', // already seeded by fixture
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

        $reservationNumber = 'ORD-NEW-999';
        $payload = [
            'number' => $reservationNumber,
            'lines' => [
                ['productSku' => 'SKU-001', 'qty' => 1],
                ['productSku' => 'SKU-001', 'qty' => 2], // duplicate SKU in lines
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

    public function test_create_dispatches_status_changed_message(): void
    {
        $this->expectSuccess();

        $reservationNumber = 'ORD-MSG-CREATE-001';

		$transport = $this->getTransportAndReset();

		$this->createReservationWithLines($reservationNumber, [
			['productSku' => 'SKU-001', 'qty' => 2],
		]);

        // Verify reservation was created
        $reservation = $this->repo->findOneByNumber($reservationNumber);
        self::assertInstanceOf(StockReservation::class, $reservation);

        // Verify message was dispatched
        $sent = $transport->getSent();
        self::assertCount(1, $sent, 'Exactly one StockReservationStatusChangedMessage should be dispatched');

        $envelope = $sent[0];
        $message = $envelope->getMessage();

        self::assertInstanceOf(StockReservationStatusChangedMessage::class, $message);
        self::assertSame($reservationNumber, $message->reservationNumber);
        self::assertNotEmpty($message->status, 'Status should be set');
        // Status could be RESERVED, RESERVED_PARTIAL, or OUT_OF_STOCK depending on stock availability
        self::assertContains($message->status, ['RESERVED', 'RESERVED_PARTIAL', 'OUT_OF_STOCK', 'PENDING']);

        // Verify lines data is included
        self::assertIsArray($message->lines);
        self::assertCount(1, $message->lines);
        $line = $message->lines[0];
        self::assertSame('SKU-001', $line['productSku']);
        self::assertArrayHasKey('qtyOrdered', $line);
        self::assertArrayHasKey('qtyReserved', $line);
        self::assertArrayHasKey('qtyShipped', $line);
        self::assertArrayHasKey('status', $line);
    }

    public function test_cancel_dispatches_status_changed_message(): void
    {
        $this->expectSuccess();

        $reservationNumber = 'ORD-MSG-CANCEL-001';

		// Create a reservation first
		$this->createReservationWithLines($reservationNumber, [
			['productSku' => 'SKU-001', 'qty' => 1],
		]);

        // Get the transport and clear messages from creation
        $this->client->disableReboot(); // On requests like these we must disable kernel reboot which clears sent msgs
		$transport = $this->getTransportAndReset();

        // Cancel the reservation
		$this->cancelReservation($reservationNumber, 202);

        // Verify message was dispatched
        $sent = $transport->getSent();
        self::assertCount(2, $sent, 'Expecting two messages: StockReservationStatusChangedMessage and ReallocateStockJob');

        $envelope = $sent[0];
        $message = $envelope->getMessage();

        self::assertInstanceOf(StockReservationStatusChangedMessage::class, $message);
        self::assertSame($reservationNumber, $message->reservationNumber);
        self::assertSame('CANCELED', $message->status);

        // Verify lines data is included
        self::assertIsArray($message->lines);
        self::assertCount(1, $message->lines);
        $line = $message->lines[0];
        self::assertSame('SKU-001', $line['productSku']);
        self::assertArrayHasKey('qtyOrdered', $line);
        self::assertArrayHasKey('qtyReserved', $line);
        self::assertArrayHasKey('qtyShipped', $line);
        self::assertArrayHasKey('status', $line);
    }

    public function test_ship_dispatches_status_changed_message(): void
    {
        $this->expectSuccess();

        $reservationNumber = 'ORD-MSG-SHIP-001';

		// Create a reservation first
		$this->createReservationWithLines($reservationNumber, [
			['productSku' => 'SKU-001', 'qty' => 1],
		]);

        // Get the transport and clear messages from creation
        $this->client->disableReboot();
		$transport = $this->getTransportAndReset();

        // Ship the reservation
		$this->shipReservation($reservationNumber, 202);

        // Verify message was dispatched
        $sent = $transport->getSent();
        self::assertCount(1, $sent, 'Exactly one StockReservationStatusChangedMessage should be dispatched on ship');

        $envelope = $sent[0];
        $message = $envelope->getMessage();

        self::assertInstanceOf(StockReservationStatusChangedMessage::class, $message);
        self::assertSame($reservationNumber, $message->reservationNumber);
        self::assertSame('SHIPPED', $message->status);

        // Verify lines data is included
        self::assertIsArray($message->lines);
        self::assertCount(1, $message->lines);
        $line = $message->lines[0];
        self::assertSame('SKU-001', $line['productSku']);
        self::assertArrayHasKey('qtyOrdered', $line);
        self::assertArrayHasKey('qtyReserved', $line);
        self::assertArrayHasKey('qtyShipped', $line);
        self::assertArrayHasKey('status', $line);
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

	private function getTransportAndReset(): InMemoryTransport
	{
		$transport = $this->c->get('messenger.transport.async');
		self::assertInstanceOf(InMemoryTransport::class, $transport);
		$transport->reset();
		return $transport;
	}
}
