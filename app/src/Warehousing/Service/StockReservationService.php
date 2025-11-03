<?php

namespace App\Warehousing\Service;

use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Exceptions\StockReservationAlreadyCancelledException;
use App\Warehousing\Messages\ReallocateStockJob;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\StockReservationRepository;
use App\Warehousing\Service\Helpers\StockAllocator;
use App\Warehousing\Service\Helpers\StockReallocationResult;
use App\Warehousing\Service\Helpers\StockShipper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class StockReservationService
{
    public function __construct(
        private EntityManagerInterface     $em,
        private StockReservationRepository $repo,
        private StockItemRepository        $stockRepo,
        private StockReservationRepository $resRepo,
        private ValidatorInterface         $validator,
        private StockAllocator             $allocator, // autowired
        private StockShipper               $shipper, // autowired
        private MessageBusInterface        $bus,
    )
    {
    }

    public function create(StockReservation $reservation): StockReservation
    {
        // Wrap in transaction
        // we use this layer since the transaction could be much broader
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($reservation): StockReservation {
            $this->allocator->allocateStockToReservationLines($reservation);

            $this->repo->create($reservation);

            return $reservation;
        });

    }

    public function cancel(StockReservation $reservation): void
    {
        $affectedSkus = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($reservation): array {
            if ($reservation->getStatus() === StockReservationStatus::CANCELED->value) {
                throw new StockReservationAlreadyCancelledException();
            }

            $this->allocator->cancel($reservation);

            $this->repo->update($reservation);

            return  $reservation->getLines()->map(fn($l) => $l->getProductSku())->toArray();
        });

        $this->queueStockReallocation($reservation->getId(), $affectedSkus);
    }

    public function queueStockReallocation(string $id, array $skus): void
    {
        $this->bus->dispatch(new ReallocateStockJob(
            'reservation:' . $id,
            $skus
        ));
    }

    public function reallocate(array $skus): StockReallocationResult
    {
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($skus): StockReallocationResult {
            $results = $this->allocator->reallocateSkus($skus);

            $this->em->flush();

            return $results;
        });
    }

    public function ship(StockReservation $reservation, bool $allowPartial = false): StockReservation
    {
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($reservation, $allowPartial): StockReservation {
            $reservation = $this->shipper->shipReservation($reservation);

            $this->resRepo->update($reservation);

            return $reservation;
        });
    }

}
