<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\DataFixtures;

use App\Warehousing\Dto\WarehouseDto;
use App\Warehousing\Factory\WarehouseFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * This is the data used for tests only
 * it does not retain in the database
 */
#[AutoconfigureTag('doctrine.fixture.orm')]
final class WarehouseTestFixture extends Fixture
{
    public function load(ObjectManager $om): void
    {
        $warehousesData = [
            [
                'code' => 'WARE-EU-1',
                'name' => 'Warehouse Europe 1',
            ],
            [
                'code' => 'WARE-EU-2',
                'name' => 'Warehouse Europe 2',
            ],
        ];

        $factory = new WarehouseFactory();

        foreach ($warehousesData as $warehouseData) {
            $dto    = WarehouseDto::fromArray($warehouseData);
            $warehouse = $factory->fromDto($dto);

            $om->persist($warehouse);
        }

        $om->flush();
    }

    /** Load with: bin/console doctrine:fixtures:load -n --group=seed (or test) */
    public static function getGroups(): array
    {
        return ['test'];
    }
}
