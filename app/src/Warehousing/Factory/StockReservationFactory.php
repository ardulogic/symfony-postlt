<?php

namespace App\Warehousing\Factory;

use App\Warehousing\Dto\StockReservationDto;
use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Entity\StockReservationLine;

/**
 * Factory is a layer between DTO and Entity by current symfony conventions
 * This conversion happens in several places, so it's good to have a class for clean structure
 */
class StockReservationFactory
{
    public function fromDto(StockReservationDto $dto): StockReservation
    {
        $order = new StockReservation(); // PENDING status by default

        return $this->updateFromDto($order, $dto);
    }

    public function updateFromDto(StockReservation $order, StockReservationDto $dto): StockReservation
    {
        $order->setNumber($dto->number);

        $lines = [];
        foreach ($dto->lines as $line) {
            $lines[]= new StockReservationLine($order, $line->productSku, $line->qty);
        }

        $order->setLines($lines);

        return $order;
    }
}
