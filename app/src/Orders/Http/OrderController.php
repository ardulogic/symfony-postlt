<?php

namespace App\Orders\Http;

use App\Orders\Dto\OrderDto;
use App\Orders\Entity\Order;
use App\Orders\Factory\OrderFactory;
use App\Orders\Repository\OrderRepository;
use App\Orders\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;


/**
 * Product Controller
 * Note: All validations are happening via Dto and Entity automatically
 */
final class OrderController extends AbstractController
{
    public function __construct(
        private ValidatorInterface $validator,
        private OrderService     $service,
        private OrderRepository  $repo,
    )
    {
    }

    public function create(
        #[MapRequestPayload(validationGroups: ['create'])] OrderDto $dto,
        OrderFactory                                              $factory,
        OrderService                                              $service,
    ): JsonResponse
    {
        $order = $factory->fromDto($dto);

        $savedOrder = $this->service->create($order);

        return $this->json($savedOrder, Response::HTTP_CREATED,
            ['Location' => $this->generateOrderUrl($savedOrder)]);
    }

    public function read(string $number, OrderRepository $repository): JsonResponse
    {
        $order = $repository->findOneByNumber($number);
        if (!$order) {
            return $this->json(['message' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        // The serializer automatically adds public getters to json
        return $this->json($order,  Response::HTTP_OK);
    }

    private function generateOrderUrl(Order $order): string
    {
        return $this->generateUrl(
            'orders_read',
            ['number' => $order->getNumber()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

}
