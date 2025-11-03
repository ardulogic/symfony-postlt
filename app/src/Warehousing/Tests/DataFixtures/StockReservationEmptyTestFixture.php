<?php

namespace App\Warehousing\Tests\DataFixtures;

use App\Orders\Dto\OrderDto;
use App\Orders\Factory\OrderFactory;
use App\Warehousing\Dto\StockReservationDto;
use App\Warehousing\Factory\StockReservationFactory;
use App\Warehousing\Service\StockReservationService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * This is the data used for tests only
 * its empty, but loads all the other dependant fixtures
 * Empty because since its quite complex to test the allocation
 * its better to have it this way first
 */
#[AutoconfigureTag('doctrine.fixture.orm')]
class StockReservationEmptyTestFixture extends Fixture  implements DependentFixtureInterface
{
    public function __construct(
        private StockReservationService $service, // injected
    ) {}

    public function load(ObjectManager $om): void
    {

    }

    /** Make sure warehouses are populated first */
    public function getDependencies(): array
    {
        // Rename to whatever your warehouse fixture class is called
        return [StockItemTestFixture::class];
    }

    /** Load with: bin/console doctrine:fixtures:load -n --group=seed (or test) */
    public static function getGroups(): array
    {
        return ['test'];
    }
}
