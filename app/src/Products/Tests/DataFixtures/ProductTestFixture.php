<?php
declare(strict_types=1);

namespace App\Products\Tests\DataFixtures;

use App\Products\Dto\ProductDto;
use App\Products\Factory\ProductFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * This is the data used for tests only
 * it does not retain in the database
 */
#[AutoconfigureTag('doctrine.fixture.orm')]
final class ProductTestFixture extends Fixture
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
        ];

        $factory = new ProductFactory();

        foreach ($productsData as $productData) {
            $dto    = ProductDto::fromArray($productData);
            $product = $factory->fromDto($dto);

            $om->persist($product);
        }

        $om->flush();
    }

    /** Load with: bin/console doctrine:fixtures:load -n --group=seed (or test) */
    public static function getGroups(): array
    {
        return ['test'];
    }
}
