<?php

namespace App\Warehousing\Messages\Handlers;

use App\Orders\Messages\OrderCreatedMessage;
use App\Warehousing\Dto\StockReservationDto;
use App\Warehousing\Dto\StockReservationLineDto;
use App\Warehousing\Factory\StockReservationFactory;
use App\Warehousing\Service\StockReservationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CreateStockReservationHandler
{
    public function __construct(
        private StockReservationFactory $factory,
        private StockReservationService $service,
    ) {
    }

    public function __invoke(OrderCreatedMessage $message): void
    {
        // Convert OrderCreatedMessage to StockReservationDto
        $dto = new StockReservationDto();
        $dto->number = $message->orderNumber;
        $dto->lines = array_map(
            fn(array $line) => StockReservationLineDto::fromArray([
                'productSku' => $line['productSku'],
                'qty' => $line['qtyOrdered'],
            ]),
            $message->lines
        );

        $reservation = $this->factory->fromDto($dto);
        $this->service->create($reservation);
    }
}
