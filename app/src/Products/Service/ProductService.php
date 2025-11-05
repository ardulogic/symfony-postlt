<?php

namespace App\Products\Service;

use App\Products\Entity\Product;
use App\Products\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProductRepository      $repo,
        private ValidatorInterface     $validator,
    )
    {
    }

    public function create(Product $product): Product
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($product): Product {
            $this->repo->create($product);
            $this->em->flush();

            return $product;
        });

    }

    public function update(Product $product)
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($product): Product {
            $em->flush();

            return $product;
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

    public function delete(Product $product)
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($product): Product {
            $this->repo->delete($product);
            $this->em->flush();

            return $product;
        });
    }

}
