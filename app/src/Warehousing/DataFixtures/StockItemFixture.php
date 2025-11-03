<?php
declare(strict_types=1);

namespace App\Warehousing\DataFixtures;

use App\Warehousing\Dto\StockReceiveDto;
use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Service\StockItemService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Demo seed data for stock items (non-test environments)
 */
#[AutoconfigureTag('doctrine.fixture.orm')]
final class StockItemFixture extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public function __construct(
        private StockItemService $stockItemService,
    ) {}

    public function load(ObjectManager $om): void
    {
        // Small, easy-to-debug quantities
        // [warehouse_code, sku, qty]
        $seed = [
            ['WARE-EU-1', 'SKU-001', 2],
            ['WARE-EU-1', 'SKU-002', 1],
            ['WARE-EU-2', 'SKU-001', 1],
            ['WARE-EU-2', 'SKU-003', 1],
        ];

        $whRepo = $om->getRepository(Warehouse::class);

        foreach ($seed as [$code, $sku, $qty]) {
            /** @var Warehouse|null $warehouse */
            $warehouse = $whRepo->findOneBy(['code' => $code]);
            if ($warehouse === null) {
                throw new \RuntimeException(sprintf('Warehouse with code "%s" not found. Ensure WarehouseFixture runs first.', $code));
            }

            $dto = StockReceiveDto::fromArray(['qty' => $qty]);
            $this->stockItemService->receive($warehouse, $sku, $dto);
        }

        $om->flush();
    }

    /** Load with: bin/console doctrine:fixtures:load -n --group=seed */
    public static function getGroups(): array
    {
        return ['seed'];
    }

    public function getDependencies(): array
    {
        return [WarehouseFixture::class];
    }
}


