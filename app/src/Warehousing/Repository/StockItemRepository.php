<?php

namespace App\Warehousing\Repository;

use App\Warehousing\Entity\StockItem;
use App\Warehousing\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class StockItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockItem::class);
    }

    /**
     * Find one StockItem by warehouse code and SKU
     * Optionally JOINs warehouse so you can access $item->getWarehouse()->getCode() without another query.
     */
    public function findOneByWarehouseCodeAndSku(string $warehouseCode, string $sku, bool $eagerWarehouse = false): ?StockItem
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('si')
            ->from(StockItem::class, 'si')
            ->innerJoin('si.warehouse', 'w');

        if ($eagerWarehouse) {
            $qb->addSelect('w');
        }

        return $qb
            ->andWhere('w.code = :code')
            ->andWhere('UPPER(si.productSku) = :skuNorm') // case-insensitive, no need to map product_sku_norm
            ->setParameter('code', $warehouseCode)
            ->setParameter('skuNorm', mb_strtoupper($sku, 'UTF-8'))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }


    public function addStock(int $warehouseId, string $sku, int $qty): StockItem
    {
        $em  = $this->getEntityManager();
        $rsm = new ResultSetMappingBuilder($em);
        $rsm->addRootEntityFromClassMetadata(StockItem::class, 's');

        $sql = <<<SQL
WITH upsert AS (
  INSERT INTO stock_items (
    id, warehouse_id, product_sku, on_hand_qty, reserved_qty, lock_version, created_at, updated_at
  ) VALUES (
    gen_random_uuid(), :wid, :sku, :qty, 0, 1, now(), now()
  )
  ON CONFLICT (warehouse_id, product_sku)
  DO UPDATE SET
    on_hand_qty  = stock_items.on_hand_qty + EXCLUDED.on_hand_qty,
    lock_version = stock_items.lock_version + 1,
    updated_at   = now()
  RETURNING
    id, warehouse_id, product_sku, on_hand_qty, reserved_qty, lock_version, created_at, updated_at
)
SELECT
  id, warehouse_id, product_sku, on_hand_qty, reserved_qty, lock_version, created_at, updated_at
FROM upsert s
SQL;

        $q = $em->createNativeQuery($sql, $rsm);
        $q->setParameters(['wid' => $warehouseId, 'sku' => $sku, 'qty' => $qty]);

        /** @var StockItem $item */
        $item = $q->getSingleResult(); // hydrated & managed
        return $item;
    }

    public function list(int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('b')
            ->orderBy('b.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $paginator = new Paginator($qb, true);

        return [iterator_to_array($paginator), $paginator->count()];
    }

    public function create(Warehouse $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);   // brand new
        $em->flush();
    }

    /**
     * Expects a MANAGED entity (loaded via this repo).
     * Just flush tracked changes.
     */
    public function update(Warehouse $managed): void
    {
        $this->getEntityManager()->flush();
    }

    public function delete(Warehouse $p): void
    {
        $this->getEntityManager()->remove($p);
        $this->getEntityManager()->flush();
    }

}
