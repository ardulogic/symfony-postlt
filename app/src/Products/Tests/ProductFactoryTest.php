<?php

namespace App\Products\Tests;

use App\Products\Dto\ProductDto;
use App\Products\Entity\Product;
use App\Products\Factory\ProductFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductFactoryTest extends TestCase
{
    private ProductFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ProductFactory();
    }

    /** Quickly create a DTO */
    private static function dto(
        string $sku = '124-456-789-UK',
        string $name = 'Dyson Toaster',
    ): ProductDto
    {
        $dto = new ProductDto();
        $dto->sku = $sku;
        $dto->name = $name;

        return $dto;
    }

    /** Check entity values vs DTO */
    private static function assertProductEqualsDto(Product $product, ProductDto $dto): void
    {
        self::assertSame($dto->sku, $product->getSku());
        self::assertSame($dto->name, $product->getName());
    }

    public function testFromDtoCreatesNewEntity(): void
    {
        $dto = self::dto();
        $product = $this->factory->fromDto($dto);

        self::assertInstanceOf(Product::class, $product);
        self::assertProductEqualsDto($product, $dto);
    }

    #[DataProvider('updateProvider')]
    public function testUpdateFromDtoMutatesExistingEntity(ProductDto $updateDto): void
    {
        // start with an entity built from defaults
        $product = $this->factory->fromDto(self::dto());
        $this->factory->updateFromDto($product, $updateDto);

        self::assertProductEqualsDto($product, $updateDto);
    }

    public static function updateProvider(): \Generator
    {
        yield 'all fields updated' => [self::dto(
            sku:'987-654-321-UK',
            name: 'Another Product',
        )];
    }

}
