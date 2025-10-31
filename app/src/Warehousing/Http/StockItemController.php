<?php

namespace App\Warehousing\Http;

use App\Warehousing\Dto\StockReceiveDto;
use App\Warehousing\Entity\StockItem;
use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Factory\WarehouseFactory;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\WarehouseRepository;
use App\Warehousing\Service\StockItemService;
use App\Warehousing\Service\WarehouseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;


/**
 * Warehouse Controller
 * Note: All validations are happening via Dto and Entity automatically
 */
final class StockItemController extends AbstractController
{
    public function __construct(
        private ValidatorInterface  $validator,
        private WarehouseService    $service,
        private WarehouseRepository $warehouseRepo,
    )
    {
    }

    public function read(string $code, string $sku, StockItemRepository $repository): JsonResponse
    {
        $stockItem = $repository->findOneByWarehouseCodeAndSku($code, $sku);
        if (!$stockItem) {
            return $this->json(['message' => 'Stock item not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($stockItem, Response::HTTP_OK);
    }

    public function receive(
        string                               $code, // warehouse code
        string                               $sku,  // product sku
        #[MapRequestPayload] StockReceiveDto $dto,
        WarehouseFactory                     $factory,
        WarehouseService                     $warehouseService,
        StockItemService                     $stockItemService,
    ): JsonResponse
    {
        $warehouse = $this->warehouseRepo->findOneByCode($code);
        if ($warehouse === null) {
            return $this->json(['error' => 'Warehouse not found'], 404);
        }

        $stockItem = $stockItemService->receive($warehouse, $sku, $dto);

        return $this->json($stockItem, Response::HTTP_CREATED,
            ['Location' => $this->generateStockItemUrl($stockItem)]);
    }

    private function generateStockItemUrl(StockItem $item): string
    {
        return $this->generateUrl(
            'stock_items_read',
            [
                'code' => $item->getWarehouse()->getCode(),
                'sku' => $item->getSku(),   // use the human SKU in the URL
            ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }
}
