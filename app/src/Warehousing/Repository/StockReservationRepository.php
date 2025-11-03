<?php

namespace App\Warehousing\Repository;

use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Service\StockReservationService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class StockReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockReservation::class, StockReservationService::class);
    }

    public function findOneByNumber(string $number): ?StockReservation
    {
        return $this->findOneBy(['number' => $number]);
    }

    /**
     * Find reservations (oldest first) that:
     *  - have status in $statuses (string values or BackedEnum),
     *  - contain lines for any of $skus,
     *
     * TODO: Have a flag for reservations as fractured so that we dont affect perfect allocations
     *
     * @param string[] $skus
     * @param array<int, string|\BackedEnum> $statuses
     * @return StockReservation[]
     */
    public function findByStatusContainingSkus(array $skus, array $statuses, int $limit = 50): array
    {
        $skus = array_values(array_unique(array_filter($skus)));
        if (!$skus) return [];

        $statusVals = array_map(
            static fn($s) => $s instanceof \BackedEnum ? $s->value : (string)$s,
            $statuses
        );

        $qb = $this->createQueryBuilder('r')
            ->select('DISTINCT r, l, w', 's')   // <-- make it a fetch-join
            ->leftJoin('r.lines', 'l')
            ->leftJoin('l.warehouse', 'w')
            ->leftJoin('l.stockItem', 's')
            ->andWhere('r.status IN (:statuses)')
            ->andWhere('l.productSku IN (:skus)')
            ->setParameter('statuses', $statusVals)
            ->setParameter('skus', $skus)
            ->setMaxResults($limit);

        $cm = $this->getEntityManager()->getClassMetadata(StockReservation::class);
        $qb->addOrderBy($cm->hasField('createdAt') ? 'r.createdAt' : 'r.id', 'ASC');

        return $qb->getQuery()->getResult(); // lines + warehouse hydrated
    }

    public function list(int $page, int $perPage): array
    {
        if ($page == 0) {
            throw new \InvalidArgumentException('Page needs to be greater than 0');
        }

        $qb = $this->createQueryBuilder('b')
            ->orderBy('b.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $paginator = new Paginator($qb, true);

        return [iterator_to_array($paginator), $paginator->count()];
    }

    public function create(StockReservation $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);   // brand new
        $em->flush();
    }

    /**
     * Expects a MANAGED entity (loaded via this repo).
     * Just flush tracked changes.
     */
    public function update(StockReservation $managed): void
    {
        $this->getEntityManager()->flush();
    }

    public function delete(StockReservation $reservation): void
    {
        $this->getEntityManager()->remove($reservation);
        $this->getEntityManager()->flush();
    }
}
