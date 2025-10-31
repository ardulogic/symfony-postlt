<?php
declare(strict_types=1);

namespace App\Warehousing\Tests\DataFixtures;

use App\Warehousing\Entity\StockItem;
use App\Warehousing\Entity\Warehouse;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('doctrine.fixture.orm')]
final class StockItemTestFixture extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $om): void
    {
        // onHand and reserved are applied via domain methods to keep invariants
        $seed = [
            // code,         sku,        onHand, reserved
            ['WARE-EU-1',   'SKU-001',   10,     0],
            ['WARE-EU-1',   'SKU-002',    5,     2],
            ['WARE-EU-2',   'SKU-001',   20,     0],
        ];

        $whRepo = $om->getRepository(Warehouse::class);

        foreach ($seed as [$code, $sku, $onHand, $reserved]) {
            /** @var Warehouse|null $warehouse */
            $warehouse = $whRepo->findOneBy(['code' => $code]);
            if ($warehouse === null) {
                throw new \RuntimeException(sprintf('Warehouse with code "%s" not found. Ensure the warehouse fixture runs first.', $code));
            }

            $item = new StockItem($warehouse, $sku);

            if ($onHand > 0) {
                $item->adjustOnHand($onHand); // +onHand (keeps checks)
            }
            if ($reserved > 0) {
                $item->reserve($reserved);    // reserve from available
            }

            $om->persist($item);

            // Optionally set references for reuse in tests
            $this->addReference(sprintf('stock.%s.%s', $code, $sku), $item);
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
