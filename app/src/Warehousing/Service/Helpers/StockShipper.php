<?php
declare(strict_types=1);

namespace App\Warehousing\Service\Helpers;

use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Entity\StockReservationLine;
use App\Warehousing\Enum\StockReservationLineStatus;
use App\Warehousing\Enum\StockReservationStatus;
use App\Warehousing\Exceptions\StockReservationAlreadyCancelledException;
use App\Warehousing\Exceptions\StockReservationAlreadyShippedException;
use App\Warehousing\Exceptions\StockReservationNothingToShipException;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\StockReservationRepository;

final class StockShipper
{
    public function __construct(
        private StockItemRepository        $stockRepo,
        private StockReservationRepository $resRepo,
    ) {}

    /**
     * Ships a reservation.
     *
     * Default: full-only shipping (only lines in RESERVED). Set $allowPartial=true to also ship RESERVED_PARTIAL lines.
     * Shipping consumes *reserved* stock; it must NOT increase available.
     */
    public function shipReservation(StockReservation $reservation, bool $allowPartial = false): StockReservation
    {
        if ($reservation->getStatus() == StockReservationStatus::CANCELED->value) {
            throw new StockReservationAlreadyCancelledException();
        }

        if ($reservation->getStatus() == StockReservationStatus::SHIPPED->value) {
            throw new StockReservationAlreadyShippedException();
        }

        $shippedAny = false;

        /** @var StockReservationLine $line */
        foreach ($reservation->getLines() as $line) {
            if (!$this->isLineShippable($line, $allowPartial)) {
                continue;
            }

            // Consume reserved at the warehouse level (does NOT return to available)
            $line->shipStock();

            $shippedAny = true;
        }

        if (!$shippedAny) {
            throw new StockReservationNothingToShipException('No lines are eligible to ship.');
        }

        $reservation->recomputeStatus();

        return $reservation;
    }

    private function isLineShippable(StockReservationLine $line, bool $allowPartial): bool
    {
        $st = $line->getStatus();

        if ($allowPartial) {
            $acceptedStatuses = [StockReservationLineStatus::RESERVED,StockReservationLineStatus::RESERVED_PARTIAL];
        } else {
            $acceptedStatuses = [StockReservationLineStatus::RESERVED];
        }

        if (!in_array($st, $acceptedStatuses, true)) {
            return false;
        }

        return $line->getReservedQty() > 0;
    }

}
