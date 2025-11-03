<?php

namespace App\Warehousing\Http;

use App\Warehousing\Dto\StockReservationDto;
use App\Warehousing\Entity\StockReservation;
use App\Warehousing\Exceptions\StockReservationAlreadyCancelledException;
use App\Warehousing\Exceptions\StockReservationAlreadyShippedException;
use App\Warehousing\Exceptions\StockReservationNothingToShipException;
use App\Warehousing\Factory\StockReservationFactory;
use App\Warehousing\Repository\StockItemRepository;
use App\Warehousing\Repository\StockReservationRepository;
use App\Warehousing\Repository\WarehouseRepository;
use App\Warehousing\Service\StockReservationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;


/**
 * Warehouse Controller
 * Note: All validations are happening via Dto and Entity automatically
 */
final class StockReservationController extends AbstractController
{
    public function __construct(
        private ValidatorInterface         $validator,
        private StockReservationService    $stockService,
        private StockReservationRepository $reservationRepo,
        private WarehouseRepository        $wareRepo,
    )
    {
    }

    public function read(string $number, StockReservationRepository $repository): JsonResponse
    {
        $reservation = $repository->findOneByNumber($number);
        if (!$reservation) {
            return $this->json(['message' => 'Stock reservation not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($reservation, Response::HTTP_OK);
    }

    public function create(
        #[MapRequestPayload(validationGroups: ['create'])] StockReservationDto $dto,
        StockReservationFactory                                                $factory,
        StockReservationService                                                $service,
    ): JsonResponse
    {
        $reservation = $factory->fromDto($dto);

        $savedReservation = $service->create($reservation);

        return $this->json($savedReservation, Response::HTTP_CREATED,
            ['Location' => $this->generateStockReservationUrl($savedReservation)]);
    }

    public function cancel(
        string                     $number,
        StockReservationFactory    $factory,
        StockReservationService    $service,
        StockReservationRepository $reservationRepo,
        StockItemRepository        $stockItemRepository,
    ): JsonResponse
    {
        $reservation = $reservationRepo->findOneByNumber($number);

        if (!$reservation) {
            return $this->json(['message' => 'Stock reservation not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $service->cancel($reservation);
        } catch (StockReservationAlreadyCancelledException) {
            return $this->json(['message' => 'Order is already cancelled'], Response::HTTP_CONFLICT);
        }

        return $this->json(
            $reservation,
            Response::HTTP_ACCEPTED,
            ['Location' => $this->generateStockReservationUrl($reservation)]
        );
    }

    public function ship(
        string                     $number,
        StockReservationService    $service,
        StockReservationRepository $reservationRepo,
        StockItemRepository        $stockItemRepository,
    ): JsonResponse
    {
        $reservation = $reservationRepo->findOneByNumber($number);

        if (!$reservation) {
            return $this->json(['message' => 'Stock reservation not found'], Response::HTTP_NOT_FOUND);
        }

        try {
            $service->ship($reservation);
        } catch (StockReservationAlreadyCancelledException) {
            return $this->json(['message' => 'Stock reservation is already cancelled.'], Response::HTTP_CONFLICT);
        } catch (StockReservationAlreadyShippedException) {
            return $this->json(['message' => 'Stock reservation is already shipped.'], Response::HTTP_CONFLICT);
        }  catch (StockReservationNothingToShipException) {
            return $this->json(['message' => 'No lines are eligible for shipping.'], Response::HTTP_CONFLICT);
        }

        return $this->json(
            $reservation,
            Response::HTTP_ACCEPTED,
            ['Location' => $this->generateStockReservationUrl($reservation)]
        );
    }

    private function generateStockReservationUrl(StockReservation $reservation): string
    {
        return $this->generateUrl(
            'stock_reservations_read',
            ['number' => $reservation->getNumber()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

}
