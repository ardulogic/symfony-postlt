<?php

namespace App\Warehousing\Service;

use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Exceptions\StockReservationAlreadyCancelledException;
use App\Warehousing\Messages\ReallocateStockJob;
use App\Warehousing\Messages\StockReservationStatusChangedMessage;
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
        $savedReservation = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($reservation): StockReservation {
            $this->em->clear();

            $this->allocator->allocateStockToReservationLines($reservation);

            $this->repo->create($reservation);

            $this->em->flush();

            return $reservation;
        });

        // Dispatch status changed message after creation
        $this->dispatchStatusChangedMessage($savedReservation);

        return $savedReservation;
    }

    public function cancel(StockReservation $reservation): ?StockReservation
    {
        [$reservation, $affectedSkus] = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($reservation): array {
            $this->em->clear();
            $reservation = $em->find(StockReservation::class, $reservation->getId());

            if ($reservation->getStatus() === StockReservationStatus::CANCELED->value) {
                throw new StockReservationAlreadyCancelledException();
            }

            $affectedSkus = [];

            // Release all items from stock repo atomically
            foreach ($reservation->getLines() as $line) {
                if ($this->repo->stockLineCanBeCancelled($line) && $line->getReservedQty() > 0) {
                    $this->stockRepo->releaseAtomically(
                        $line->getWarehouse()->getId(),
                        $line->getProductSku(),
                        $line->getReservedQty()
                    );

                    $affectedSkus[$line->getProductSku()] = true;
                }
            }

            $this->repo->cancel($reservation);

            $this->em->flush();

            return [$reservation, array_keys($affectedSkus)];
        });

//         Dispatch status changed message after cancellation
        $this->dispatchStatusChangedMessage($reservation);

        $this->dispatchStockReallocation($reservation->getId(), $affectedSkus);

        return $reservation;
    }

    public function reallocate(array $skus): StockReallocationResult
    {
        return $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($skus): StockReallocationResult {
            $this->em->clear();

            $results = $this->allocator->reallocateSkus($skus);

            $this->em->flush();

            return $results;
        });
    }

    public function ship(StockReservation $reservation, bool $allowPartial = false): StockReservation
    {
        $shippedReservation = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($reservation, $allowPartial): StockReservation {
            $this->em->clear();
            $reservation = $em->find(StockReservation::class, $reservation->getId());

            $reservation = $this->shipper->shipReservation($reservation);

            $this->em->flush();

            return $reservation;
        });

        // Dispatch status changed message after shipping
        $this->dispatchStatusChangedMessage($shippedReservation);

        return $shippedReservation;
    }

    public function dispatchStockReallocation(string $id, array $skus): void
    {
        $this->bus->dispatch(new ReallocateStockJob(
            'allocation-id:' . $id,
            $skus
        ));
    }

    private function dispatchStatusChangedMessage(StockReservation $reservation): void
    {
        $lines = [];
        foreach ($reservation->getLines() as $line) {
            $lines[] = [
                'productSku' => $line->getProductSku(),
                'qtyOrdered' => $line->getOrderedQty(),
                'qtyReserved' => $line->getReservedQty(),
                'qtyShipped' => $line->getShippedQty(),
                'status' => $line->getStatus()->value,
            ];
        }

        $this->bus->dispatch(new StockReservationStatusChangedMessage(
            reservationNumber: $reservation->getNumber(),
            status: $reservation->getStatus(),
            lines: $lines,
        ));
    }

}
