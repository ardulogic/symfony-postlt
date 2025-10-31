<?php
declare(strict_types=1);

namespace App\Orders\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class OrderDto
{
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(max: 32, groups: ['create'])]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9._-]+$/i',
        message: 'Order number may contain A–Z, 0–9, dot, underscore, hyphen.',
        groups: ['create']
    )]
    public string $number;

    /** @var OrderLineDto[] */
    #[Assert\NotNull(groups: ['create'])]
    #[Assert\Count(min: 1, groups: ['create'])]
    #[Assert\Valid(groups: ['create'])]
    public array $lines = [];

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->number = (string)($data['number'] ?? '');
        $dto->lines = array_map(
            static fn(array $l) => OrderLineDto::fromArray($l),
            (array)($data['lines'] ?? [])
        );
        return $dto;
    }
}
