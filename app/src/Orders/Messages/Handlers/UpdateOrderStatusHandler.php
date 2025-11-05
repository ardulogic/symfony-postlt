<?php

namespace App\Orders\Messages\Handlers;

use App\Orders\Entity\Order;
use App\Orders\Enum\OrderLineStatus;
use App\Orders\Enum\OrderStatus;
use App\Orders\Repository\OrderRepository;
use App\Warehousing\Messages\StockReservationStatusChangedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class UpdateOrderStatusHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(StockReservationStatusChangedMessage $message): void
    {
        // Find order by number (which matches reservation number)
        $order = $this->orderRepository->findOneByNumber($message->reservationNumber);

        if (!$order) {
            // Order not found - might not exist yet or already deleted
            // Log this but don't throw to avoid message retry loops
            return;
        }

        // Map stock reservation status to order status (statuses are now the same)
        $orderStatus = $this->mapReservationStatusToOrderStatus($message->status);

        $this->em->wrapInTransaction(function () use ($order, $orderStatus, $message) {
            $this->em->clear();

            // Set status directly from warehousing - Order does not compute its own status
            if ($orderStatus) {
                $order->setStatus($orderStatus);
            }

            // Update order lines from warehousing message
            $this->updateOrderLinesFromMessage($order, $message->lines);

            $this->em->flush();
        });
    }

    /**
     * @param array<array{productSku: string, qtyOrdered: int, qtyReserved: int, qtyShipped: int, status: string}> $warehousingLines
     */
    private function updateOrderLinesFromMessage(Order $order, array $warehousingLines): void
    {
        $this->em->clear();

        // Create a map of SKU to warehousing line data for quick lookup
        $warehousingLineMap = [];
        foreach ($warehousingLines as $line) {
            $warehousingLineMap[$line['productSku']] = $line;
        }

        // Update existing order lines with warehousing data
        foreach ($order->getLines() as $orderLine) {
            $sku = $orderLine->getProductSku();

            if (isset($warehousingLineMap[$sku])) {
                $warehousingLine = $warehousingLineMap[$sku];

                // Set quantities and status directly from warehousing (no recomputation)
                $orderLine->setQtyReserved($warehousingLine['qtyReserved']);
                $orderLine->setQtyShipped($warehousingLine['qtyShipped']);

                // Map warehousing line status to order line status
                $orderLineStatus = $this->mapReservationLineStatusToOrderLineStatus($warehousingLine['status']);
                if ($orderLineStatus) {
                    $orderLine->setStatus($orderLineStatus);
                }
            }
        }

        $this->em->flush();
    }

    private function mapReservationLineStatusToOrderLineStatus(string $reservationLineStatus): ?OrderLineStatus
    {
        return match ($reservationLineStatus) {
            'PENDING' => OrderLineStatus::PENDING,
            'RESERVED' => OrderLineStatus::RESERVED,
            'RESERVED_PARTIAL' => OrderLineStatus::RESERVED_PARTIAL,
            'SHIPPED' => OrderLineStatus::SHIPPED,
            'SHIPPED_PARTIAL' => OrderLineStatus::SHIPPED_PARTIAL, // Map partial shipped to shipped for orders
            'CANCELED' => OrderLineStatus::CANCELED,
            'OUT_OF_STOCK' => OrderLineStatus::OUT_OF_STOCK, // Out of stock is still pending from order perspective
            default => null, // Unknown status, don't update
        };
    }

    private function mapReservationStatusToOrderStatus(string $reservationStatus): ?OrderStatus
    {
        // Status values are identical between OrderStatus and StockReservationStatus enums
        return match ($reservationStatus) {
            'PENDING' => OrderStatus::PENDING,
            'RESERVED' => OrderStatus::RESERVED,
            'RESERVED_PARTIAL' => OrderStatus::RESERVED_PARTIAL,
            'SHIPPED' => OrderStatus::SHIPPED,
            'CANCELED' => OrderStatus::CANCELED,
            'OUT_OF_STOCK' => OrderStatus::OUT_OF_STOCK,
            default => null, // Unknown status, don't update
        };
    }
}
