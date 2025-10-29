<?php
namespace App\Health\Http;

use App\Health\HealthCheckService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class HealthController
{
    public function __construct(private readonly HealthCheckService $health) {}

    public function live(): Response
    {
        return new Response('', Response::HTTP_OK);
    }

    public function ready(): JsonResponse
    {
        $status = $this->health->probeAll();

        return new JsonResponse(
            $status,
            $status['ok'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
            ['Cache-Control' => 'no-store']
        );
    }
}
