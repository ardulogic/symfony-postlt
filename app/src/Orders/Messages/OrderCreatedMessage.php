<?php

namespace App\Orders\Messages;

final class OrderCreatedMessage
{
    /**
     * @param array<array{productSku: string, qtyOrdered: int}> $lines
     */
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $status,
        public readonly array $lines,
    ) {}
}
