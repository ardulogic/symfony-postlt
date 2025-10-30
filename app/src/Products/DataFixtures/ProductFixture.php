<?php

namespace App\Products\DataFixtures;

use App\Products\Dto\ProductDto;
use App\Products\Factory\ProductFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * This fixture is used for seeding the demo data in the database
 */
#[AutoconfigureTag('doctrine.fixture.orm')]
final class ProductFixture extends Fixture implements FixtureGroupInterface
{
    public function load(ObjectManager $om): void
    {
        $productsData = [
            [
                'sku' => 'SKU-001',
                'name' => 'Product 1',
            ],
            [
                'sku' => 'SKU-002',
                'name' => 'Product 2',
            ],
            [
                'sku' => 'SKU-003',
                'name' => 'Product 3',
            ],
        ];

        $factory = new ProductFactory();

        foreach ($productsData as $productData) {
            $dto    = ProductDto::fromArray($productData);
            $product = $factory->fromDto($dto);

            $om->persist($product);
        }

        $om->flush();
    }

    /** Load with: bin/console doctrine:fixtures:load -n --group=seed */
    public static function getGroups(): array
    {
        return ['seed']; // or ['seed','test'] if you want both
    }


}
