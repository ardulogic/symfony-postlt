<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\DataFixtures;

use App\Warehousing\Dto\StockReceiveDto;
use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Service\StockItemService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('doctrine.fixture.orm')]
final class StockItemTestFixture extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private StockItemService $service, // injected
    ) {}

    public function load(ObjectManager $om): void
    {
        // [warehouse_code, sku, qty]
        $seed = [
            ['WARE-EU-1', 'SKU-001', 1],
            ['WARE-EU-2', 'SKU-001', 2],
            ['WARE-EU-3', 'SKU-001', 3],
            ['WARE-EU-1', 'SKU-002', 1],
            ['WARE-EU-2', 'SKU-002', 2],
            ['WARE-EU-3', 'SKU-002', 3],
            ['WARE-EU-4', 'SKU-004', 0],
        ];

        $whRepo = $om->getRepository(Warehouse::class);

        foreach ($seed as [$code, $sku, $qty]) {
            /** @var Warehouse|null $warehouse */
            $warehouse = $whRepo->findOneBy(['code' => $code]);
            if ($warehouse === null) {
                throw new \RuntimeException(sprintf('Warehouse with code "%s" not found. Ensure the warehouse fixture runs first.', $code));
            }

            $dto = StockReceiveDto::fromArray(['qty' => $qty]);
            $this->service->receive($warehouse, $sku, $dto);
        }

        $om->flush();
    }

    /** Make sure warehouses are loaded first */
    public function getDependencies(): array
    {
        // Rename to whatever your warehouse fixture class is called
        return [WarehouseTestFixture::class];
    }

    /** Load with: bin/console doctrine:fixtures:load -n --group=test */
    public static function getGroups(): array
    {
        return ['test'];
    }
}
