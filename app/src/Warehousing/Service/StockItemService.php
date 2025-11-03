<?php

namespace App\Warehousing\Service;

use App\Warehousing\Dto\StockReceiveDto;
use App\Warehousing\Entity\StockItem;
use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\WarehouseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class StockItemService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WarehouseRepository    $warehouseRepo,
        private StockItemRepository    $stockItemRepo,
        private ValidatorInterface     $validator,
        private StockReservationService $stockReservationService,
    )
    {
    }

    public function receive(Warehouse $warehouse, string $sku, StockReceiveDto $dto): StockItem
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        $item = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($warehouse, $sku, $dto): StockItem {
            return $this->stockItemRepo->addStock($warehouse->getId(), $sku, $dto->qty);
        });

        // Trigger reallocation for the affected SKU
        $this->stockReservationService->dispatchStockReallocation(
            'stock-receive:' . ($item->getId() ?? uniqid('si:', true)),
            [$sku]
        );

        return $item;

    }


}
