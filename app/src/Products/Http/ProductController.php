<?php

namespace App\Products\Http;

use App\Products\Dto\ProductDto;
use App\Products\Entity\Product;
use App\Products\Factory\ProductFactory;
use App\Products\ProductService;
use App\Products\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;


final class ProductController extends AbstractController
{
    public function __construct(
        private ValidatorInterface $validator,
        private ProductService     $service,
        private ProductRepository  $repo,
    )
    {
    }

    public function create(
        #[MapRequestPayload(validationGroups: ['create'])] ProductDto $dto,
        ProductFactory                                                $factory,
        ProductService                                                $service,
    ): JsonResponse
    {
        $product = $factory->fromDto($dto);

        // Validation is handled via DTO/Entity
        $savedProduct = $this->service->create($product);

        return $this->json($savedProduct, Response::HTTP_CREATED,
            ['Location' => $this->generateProductUrl($savedProduct)]);
    }

    public function read(string $sku, ProductRepository $repository): JsonResponse
    {
        $product = $repository->findOneBySku($sku);
        if (!$product) {
            return $this->json(['message' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($product, Response::HTTP_OK);
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
        string                                                        $sku,
        #[MapRequestPayload(validationGroups: ['update'])] ProductDto $dto,
        ProductRepository                                             $repository,
        ProductFactory                                                $factory,
        ProductService                                                $service,
    ): JsonResponse
    {
        $existingProduct = $repository->findOneBySku($sku);

        if (!$existingProduct) {
            return $this->json(['message' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        $factory->updateFromDto($existingProduct, $dto);

        // Validation is handled via DTO/Entity
        $updatedProduct = $this->service->update($existingProduct);

        return $this->json($updatedProduct, Response::HTTP_OK,
            ['Location' => $this->generateProductUrl($updatedProduct)]);
    }

    public function delete(
        string            $sku,
        ProductRepository $repository,
        ProductFactory    $factory,
        ProductService    $service,
    ): Response
    {
        $product = $repository->findOneBySku($sku);

        if (!$product) {
            return $this->json(['message' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        $service->delete($product);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function generateProductUrl(Product $product): string
    {
        return $this->generateUrl(
            'products_read',
            ['sku' => $product->getSku()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

}
