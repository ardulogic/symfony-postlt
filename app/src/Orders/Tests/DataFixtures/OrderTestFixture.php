<?php

namespace App\Orders\Tests\DataFixtures;

use App\Orders\Dto\OrderDto;
use App\Orders\Factory\OrderFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * This is the data used for tests only
 * it does not retain in the database
 */
#[AutoconfigureTag('doctrine.fixture.orm')]
class OrderTestFixture extends Fixture
{
    public function load(ObjectManager $om): void
    {
        $ordersData = [
            [
                'number' => 'ORD-TEST-001',
                'lines'  => [
                    ['productSku' => 'SKU-001', 'qty' => 3],
                    ['productSku' => 'SKU-002', 'qty' => 1],
                ],
            ],
            [
                'number' => 'ORD-TEST-002',
                'lines'  => [
                    ['productSku' => 'SKU-002', 'qty' => 10],
                ],
            ],
        ];

        $factory = new OrderFactory();

        foreach ($ordersData as $orderData) {
            $dto    = OrderDto::fromArray($orderData);
            $order = $factory->fromDto($dto);

            $om->persist($order);
        }

        $om->flush();
    }

    /** Load with: bin/console doctrine:fixtures:load -n --group=seed (or test) */
    public static function getGroups(): array
    {
        return ['test'];
    }
}
