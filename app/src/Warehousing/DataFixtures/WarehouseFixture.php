<?php
declare(strict_types=1);

namespace App\Warehousing\DataFixtures;

use App\Warehousing\Dto\WarehouseDto;
use App\Warehousing\Factory\WarehouseFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Demo seed data for warehouses (non-test environments)
 */
#[AutoconfigureTag('doctrine.fixture.orm')]
final class WarehouseFixture extends Fixture implements FixtureGroupInterface
{
    public function load(ObjectManager $om): void
    {
        $warehousesData = [
            ['code' => 'WARE-EU-1', 'name' => 'Warehouse EU 1'],
            ['code' => 'WARE-EU-2', 'name' => 'Warehouse EU 2'],
            ['code' => 'WARE-EU-3', 'name' => 'Warehouse EU 3'],
        ];

        $factory = new WarehouseFactory();

        foreach ($warehousesData as $warehouseData) {
            $dto = WarehouseDto::fromArray($warehouseData);
            $warehouse = $factory->fromDto($dto);
            $om->persist($warehouse);
        }

        $om->flush();
    }

    /** Load with: bin/console doctrine:fixtures:load -n --group=seed */
    public static function getGroups(): array
    {
        return ['seed'];
    }
}


