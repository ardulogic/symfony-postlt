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
            $this->allocator->allocateStockToReservationLines($reservation);

            $this->repo->create($reservation);

            return $reservation;
        });

        // Dispatch status changed message after creation
        $this->dispatchStatusChangedMessage($savedReservation);

        return $savedReservation;
    }

    public function cancel(StockReservation $reservation): ?StockReservation
    {
        $updated = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($reservation): StockReservation {
            if ($reservation->getStatus() === StockReservationStatus::CANCELED->value) {
                throw new StockReservationAlreadyCancelledException();
            }

            $this->allocator->cancel($reservation); // This calls recomputeStatus() internally

            $this->repo->update($reservation);

            return $reservation;
        });

        // Force initialization of lines collection while still in transaction
        // Iterate over the collection to ensure it's fully loaded before transaction ends
        $affectedSkus = [];
        foreach ($updated->getLines() as $line) {
            $affectedSkus[] = $line->getProductSku();
        }

        // Dispatch status changed message after cancellation
        $this->dispatchStatusChangedMessage($updated);

        $this->dispatchStockReallocation($updated->getId(), $affectedSkus);

        return $updated;
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
        $shippedReservation = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($reservation, $allowPartial): StockReservation {
            $reservation = $this->shipper->shipReservation($reservation);

            $this->repo->update($reservation);

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
