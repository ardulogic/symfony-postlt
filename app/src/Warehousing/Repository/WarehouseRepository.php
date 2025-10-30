<?php

namespace App\Warehousing\Repository;

use App\Warehousing\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class WarehouseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Warehouse::class);
    }

    public function findOneByCode(string $code): ?Warehouse
    {
        return $this->findOneBy(['code' => $code]);
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
