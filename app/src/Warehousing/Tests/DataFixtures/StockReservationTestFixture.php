<?php

namespace App\Warehousing\Tests\DataFixtures;

use App\Warehousing\Dto\StockReservationDto;
use App\Warehousing\Factory\StockReservationFactory;
use App\Warehousing\Service\StockReservationService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * This is the data used for tests only
 * it does not retain in the database
 */
#[AutoconfigureTag('doctrine.fixture.orm')]
class StockReservationTestFixture extends Fixture  implements DependentFixtureInterface
{
    public function __construct(
        private StockReservationService $service, // injected
    ) {}

    public function load(ObjectManager $om): void
    {
        $stockReservationsData = [
            [
                'number' => 'ORD-TEST-001',
                'lines'  => [
                    ['productSku' => 'SKU-001', 'qty' => 2],
                    ['productSku' => 'SKU-002', 'qty' => 2],
                ],
            ],
        ];

        $factory = new StockReservationFactory();

        foreach ($stockReservationsData as $stockReservationData) {
            $dto    = StockReservationDto::fromArray($stockReservationData);
            $reservation = $factory->fromDto($dto);

            $this->service->create($reservation);

            $om->persist($reservation);
        }

        $om->flush();
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
