<?php
declare(strict_types=1);

namespace App\Shared\Http;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 255]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $req = $event->getRequest();

        // Only handle API requests (adjust as you like)
        if (!$this->wantsJson($req)) {
            return;
        }

        $e = $event->getThrowable();

        // Doctrine unique constraint (race-safe duplicate)
        if ($e instanceof UnprocessableEntityHttpException) {
            $event->setResponse($this->problem(422, 'Data error', ['_global' => [$e->getMessage()]]));
            return;
        }

        // Doctrine unique constraint (race-safe duplicate)
        if ($e instanceof UniqueConstraintViolationException) {
            $event->setResponse($this->problem(409, 'Duplicate resource', ['_global' => ['Unique constraint violated.']]));
            return;
        }

        // Validation (DTO mapping, entity validation, etc.)
        if ($e instanceof ValidationFailedException) {
            $violMap = $this->violationsToMap($e->getViolations());

            // If any violation is UniqueEntity → prefer 409
            foreach ($e->getViolations() as $v) {
                if ($v->getCode() === UniqueEntity::NOT_UNIQUE_ERROR || $v->getConstraint() instanceof UniqueEntity) {
                    $event->setResponse($this->problem(409, 'Duplicate resource', $violMap));
                    return;
                }
            }

            $event->setResponse($this->problem(422, 'Validation failed', $violMap));
            return;
        }

        // Bad JSON / type mismatches during denormalization
        if ($e instanceof NotNormalizableValueException) {
            $event->setResponse($this->problem(400, 'Invalid request payload', [
                '_payload' => [$e->getMessage()],
            ]));
            return;
        }

        // Any explicit HttpException keeps its code/message
        if ($e instanceof HttpExceptionInterface) {
            $event->setResponse($this->problem($e->getStatusCode(), $e->getMessage() ?: 'Error'));
            return;
        }

        // Fallback
        $event->setResponse($this->problem(500, 'Internal Server Error'));
    }

    private function wantsJson(Request $r): bool
    {
        // Choose one strategy:
        // a) Prefix routing for APIs
        if (str_starts_with($r->getPathInfo(), '/api')) {
            return true;
        }
        // b) Or content negotiation
        return str_contains($r->headers->get('Accept', ''), 'application/json')
            || str_contains($r->headers->get('Accept', ''), 'application/problem+json');
    }

    private function violationsToMap(ConstraintViolationListInterface $list): array
    {
        $out = [];
        /** @var ConstraintViolationInterface $v */
        foreach ($list as $v) {
            $field = $v->getPropertyPath() ?: '_global';
            $out[$field][] = $v->getMessage();
        }
        return $out;
    }

    private function problem(int $status, string $title, array $errors = []): JsonResponse
    {
        $body = [
            'type'   => 'about:blank',
            'title'  => $title,
            'status' => $status,
        ];
        if ($errors) {
            $body['errors'] = $errors; // map: field => [messages]
        }

        return new JsonResponse(
            $body,
            $status,
            ['Content-Type' => 'application/problem+json']
        );
    }
}
