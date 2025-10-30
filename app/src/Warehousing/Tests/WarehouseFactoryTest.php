<?php

namespace App\Warehousing\Tests;

use App\Warehousing\Dto\WarehouseDto;
use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Factory\WarehouseFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WarehouseFactoryTest extends TestCase
{
    private WarehouseFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new WarehouseFactory();
    }

    /** Quickly create a DTO */
    private static function dto(
        string $code = 'WARE-EU-01',
        string $name = 'Warehouse Europe 1',
    ): WarehouseDto
    {
        $dto = new WarehouseDto();
        $dto->code = $code;
        $dto->name = $name;

        return $dto;
    }

    /** Check entity values vs DTO */
    private static function assertWarehouseEqualsDto(Warehouse $warehouse, WarehouseDto $dto): void
    {
        self::assertSame($dto->code, $warehouse->getCode());
        self::assertSame($dto->name, $warehouse->getName());
    }

    public function testFromDtoCreatesNewEntity(): void
    {
        $dto = self::dto();
        $warehouse = $this->factory->fromDto($dto);

        self::assertInstanceOf(Warehouse::class, $warehouse);
        self::assertWarehouseEqualsDto($warehouse, $dto);
    }

    #[DataProvider('updateProvider')]
    public function testUpdateFromDtoMutatesExistingEntity(WarehouseDto $updateDto): void
    {
        // start with an entity built from defaults
        $warehouse = $this->factory->fromDto(self::dto());
        $this->factory->updateFromDto($warehouse, $updateDto);

        self::assertWarehouseEqualsDto($warehouse, $updateDto);
    }

    public static function updateProvider(): \Generator
    {
        yield 'all fields updated' => [self::dto(
            code:'987-654-321-UK',
            name: 'Another Warehouse',
        )];
    }

}
