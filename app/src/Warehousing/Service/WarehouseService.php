<?php

namespace App\Warehousing\Service;

use App\Warehousing\Entity\Warehouse;
use App\Warehousing\Repository\WarehouseRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class WarehouseService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WarehouseRepository      $repo,
        private ValidatorInterface     $validator,
    )
    {
    }

    public function create(Warehouse $warehouse): Warehouse
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($warehouse): Warehouse {
            $this->em->clear();
            $this->repo->create($warehouse);
            $this->em->flush();

            return $warehouse;
        });

    }

    public function update(Warehouse $warehouse)
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($warehouse): Warehouse {

            $em->flush();

            return $warehouse;
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

    public function delete(Warehouse $warehouse)
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($warehouse): Warehouse {
            $this->repo->delete($warehouse);

            return $warehouse;
        });
    }

}
