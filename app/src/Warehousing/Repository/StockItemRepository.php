<?php

namespace App\Warehousing\Repository;

use App\Warehousing\Entity\StockItem;
use App\Warehousing\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
    public function findOneByWarehouseCodeAndSku(string $warehouseCode, string $sku): ?StockItem
    {
        return $this->createQueryBuilder('si')
            ->leftJoin('si.warehouse', 'w')
            ->andWhere('w.code = :code')
            ->andWhere('si.productSku = :sku')
            ->setParameter('code', trim($warehouseCode))
            ->setParameter('sku', trim($sku))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }


    public function addStock(int $warehouseId, string $sku, int $qty): StockItem
    {
        $em = $this->getEntityManager();
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

    /**
     * Availability map: [ warehouseId => [ sku => ['available'=>int,'item_id'=>uuid,'warehouse_code'=>string] ] ]
     * Only returns rows with available > 0.
     */
    public function getBySkusSortedByDescAvailability(array $skus): array
    {
        $skus = array_values(array_unique(array_filter($skus)));
        if (!$skus) return [];

        $em = $this->getEntityManager();
        $rsm = new ResultSetMappingBuilder($em);

        // Alias must match your SQL alias ("si")
        $alias = 'si';
        $rsm->addRootEntityFromClassMetadata(StockItem::class, $alias);

        // Let Doctrine build the SELECT list for the entity columns
        $selectEntityCols = $rsm->generateSelectClause([$alias => $alias]);

        $sql = <<<SQL
            SELECT
                {$selectEntityCols},
                w.code AS warehouse_code,
                GREATEST(si.on_hand_qty - si.reserved_qty, 0) AS available
            FROM stock_items si
            JOIN warehouses w ON w.id = si.warehouse_id
            WHERE si.product_sku IN (:skus)
            ORDER BY available DESC
        SQL;

        $q = $em->createNativeQuery($sql, $rsm);
        $q->setParameter('skus', $skus, ArrayParameterType::STRING);

        return $q->getResult();
    }

    /**
     * Atomically reserve stock up to the requested quantity. Returns the actual quantity reserved.
     * This method reserves as much as available, up to the requested amount.
     */
    public function reserveAtomicallyUpTo(int $warehouseId, string $sku, int $maxQty): int
    {
        if ($maxQty <= 0) return 0;

        $conn = $this->getEntityManager()->getConnection();
        // Use a CTE to calculate available quantity first, then reserve and return the reserved amount
        $sql = <<<SQL
            WITH available_calc AS (
                SELECT
                    id,
                    on_hand_qty,
                    reserved_qty,
                    GREATEST(0, LEAST(:max_qty, on_hand_qty - reserved_qty)) AS qty_to_reserve
                FROM stock_items
                WHERE warehouse_id = :wid
                  AND product_sku = :sku
                  AND (on_hand_qty - reserved_qty) > 0
            ),
            reservation AS (
                UPDATE stock_items si
                SET reserved_qty = si.reserved_qty + ac.qty_to_reserve,
                    lock_version = si.lock_version + 1,
                    updated_at = NOW()
                FROM available_calc ac
                WHERE si.id = ac.id
                RETURNING ac.qty_to_reserve AS reserved
            )
            SELECT COALESCE(reserved, 0) FROM reservation
        SQL;

        $result = $conn->executeQuery($sql, [
            'max_qty' => $maxQty,
            'wid' => $warehouseId,
            'sku' => $sku,
        ]);

        $row = $result->fetchOne();
        return $row ? (int)$row : 0;
    }

    /**
     * If you cancel a reservation before shipping.
     */
    public function releaseAtomically(int $warehouseId, string $sku, int $qty): bool
    {
        if ($qty <= 0) return false;

        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<SQL
            UPDATE stock_items
               SET reserved_qty = reserved_qty - :qty,
                   lock_version  = lock_version + 1,
                   updated_at    = NOW()
             WHERE warehouse_id = :wid
               AND product_sku  = :sku
               AND reserved_qty >= :qty
        SQL;

        return $conn->executeStatement($sql, [
                'qty' => $qty,
                'wid' => $warehouseId,
                'sku' => $sku,
            ]) === 1;
    }

    public function list(int $page, int $perPage): array
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be >= 1');
        }

        $qb = $this->createQueryBuilder('si')
            ->addSelect('w')                    // fetch-join the warehouse
            ->join('si.warehouse', 'w')         // INNER JOIN (use leftJoin if you must)
            ->orderBy('w.code', 'ASC')
            ->addOrderBy('si.productSku', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        // Paginator works fine with fetch-join on ToOne associations
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
