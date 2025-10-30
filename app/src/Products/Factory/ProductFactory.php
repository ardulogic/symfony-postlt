<?php

namespace App\Products\Factory;

use App\Products\Dto\ProductDto;
use App\Products\Entity\Product;

/**
 * Factory is a layer between DTO and Entity by current symfony conventions
 * This conversion happens in several places, so it's good to have a class for clean structure
 */
class ProductFactory
{
    public function fromDto(ProductDto $dto): Product
    {
        $product = new Product();

        return $this->updateFromDto($product, $dto);
    }

    public function updateFromDto(Product $product, ProductDto $dto): Product
    {
        if ($dto->sku !== null) $product->setSku($dto->sku);
        if ($dto->name !== null) $product->setName($dto->name);

        return $product;
    }
}
