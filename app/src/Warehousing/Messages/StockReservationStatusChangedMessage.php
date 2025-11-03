<?php

namespace App\Warehousing\Messages;

final class StockReservationStatusChangedMessage
{
    /**
     * @param array<array{productSku: string, qtyOrdered: int, qtyReserved: int, qtyShipped: int, status: string}> $lines
     */
    public function __construct(
        public readonly string $reservationNumber,
        public readonly string $status, // RESERVED, RESERVED_PARTIAL, SHIPPED, CANCELED, OUT_OF_STOCK
        public readonly array $lines,
    ) {}
}
