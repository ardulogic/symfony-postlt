<?php

namespace App\Orders\Repository;

use App\Orders\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findOneByNumber(string $number): ?Order
    {
        return $this->findOneBy(['number' => $number]);
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

    public function create(Order $entity): void
    {
        $em = $this->getEntityManager();
        $em->persist($entity);   // brand new
    }

    public function delete(Order $order): void
    {
        $this->getEntityManager()->remove($order);
    }
}
