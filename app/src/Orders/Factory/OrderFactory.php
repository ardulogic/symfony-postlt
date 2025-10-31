<?php

namespace App\Orders\Factory;

use App\Orders\Dto\OrderDto;
use App\Orders\Entity\Order;
use App\Orders\Entity\OrderLine;

class OrderFactory
{
    public function fromDto(OrderDto $dto): Order
    {
        $order = new Order(); // PENDING status by default

        return $this->updateFromDto($order, $dto);
    }

    public function updateFromDto(Order $order, OrderDto $dto): Order
    {
        $order->setNumber($dto->number);

        $lines = [];
        foreach ($dto->lines as $line) {
            $lines[]= new OrderLine($order, $line->productSku, $line->qty);
        }

        $order->setLines($lines);

        return $order;
    }
}
