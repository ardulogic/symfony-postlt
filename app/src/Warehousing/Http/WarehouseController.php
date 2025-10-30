<?php

namespace App\Warehousing\Http;

use App\Warehousing\Dto\WarehouseDto;
use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Factory\WarehouseFactory;
use App\Warehousing\Repository\WarehouseRepository;
use App\Warehousing\Service\WarehouseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;


/**
 * Warehouse Controller
 * Note: All validations are happening via Dto and Entity automatically
 */
final class WarehouseController extends AbstractController
{
    public function __construct(
        private ValidatorInterface $validator,
        private WarehouseService     $service,
        private WarehouseRepository  $repo,
    )
    {
    }

    public function create(
        #[MapRequestPayload(validationGroups: ['create'])] WarehouseDto $dto,
        WarehouseFactory                                                $factory,
        WarehouseService                                                $service,
    ): JsonResponse
    {
        $warehouse = $factory->fromDto($dto);

        $savedWarehouse = $this->service->create($warehouse);

        return $this->json($savedWarehouse, Response::HTTP_CREATED,
            ['Location' => $this->generateWarehouseUrl($savedWarehouse)]);
    }

    public function read(string $code, WarehouseRepository $repository): JsonResponse
    {
        $warehouse = $repository->findOneByCode($code);
        if (!$warehouse) {
            return $this->json(['message' => 'Warehouse not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($warehouse, Response::HTTP_OK);
    }

    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = min(max(1, (int)$request->query->get('per_page', 20)), 100);

        [$items, $total] = $this->service->list($page, $perPage);

        return $this->json([
            'data' => $items, // Serialization happens via entity
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int)ceil($total / $perPage),
            ],
        ], Response::HTTP_OK);
    }

    public function update(
        string                                                        $code,
        #[MapRequestPayload(validationGroups: ['update'])] WarehouseDto $dto,
        WarehouseRepository                                             $repository,
        WarehouseFactory                                                $factory,
        WarehouseService                                                $service,
    ): JsonResponse
    {
        $existingWarehouse = $repository->findOneByCode($code);

        if (!$existingWarehouse) {
            return $this->json(['message' => 'Warehouse not found'], Response::HTTP_NOT_FOUND);
        }

        $factory->updateFromDto($existingWarehouse, $dto);

        $updatedWarehouse = $this->service->update($existingWarehouse);

        return $this->json($updatedWarehouse, Response::HTTP_OK,
            ['Location' => $this->generateWarehouseUrl($updatedWarehouse)]);
    }

    public function delete(
        string            $code,
        WarehouseRepository $repository,
        WarehouseFactory    $factory,
        WarehouseService    $service,
    ): Response
    {
        $warehouse = $repository->findOneByCode($code);

        if (!$warehouse) {
            return $this->json(['message' => 'Warehouse not found'], Response::HTTP_NOT_FOUND);
        }

        $service->delete($warehouse);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function generateWarehouseUrl(Warehouse $warehouse): string
    {
        return $this->generateUrl(
            'warehouses_read',
            ['code' => $warehouse->getCode()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

}
