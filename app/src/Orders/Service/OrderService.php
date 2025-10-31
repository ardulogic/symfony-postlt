<?php

namespace App\Orders\Service;

use App\Orders\Entity\Order;
use App\Orders\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class OrderService
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrderRepository      $repo,
        private ValidatorInterface     $validator,
    )
    {
    }

    public function create(Order $order): Order
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($order): Order {
            $this->repo->create($order);

            return $order;
        });

    }

    public function update(Order $order)
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($order): Order {
            $this->repo->update($order);

            return $order;
        });
    }

    public function list(int $page, int $perPage)
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($page, $perPage): array {
            return $this->repo->list($page, $perPage);
        });
    }

    public function delete(Order $order)
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($order): Order {
            $this->repo->delete($order);

            return $order;
        });
    }

}
