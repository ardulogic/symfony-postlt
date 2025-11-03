<?php

namespace App\Warehousing\Dto;


use Symfony\Component\Validator\Constraints as Assert;

final class StockReservationLineDto
{
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(max: 64, groups: ['create'])]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9._-]+$/i',
        message: 'SKU may contain A–Z, 0–9, dot, underscore, hyphen.',
        groups: ['create']
    )]
    public string $productSku;

    #[Assert\Positive(groups: ['create'])]
    public int $qty;

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->productSku = (string)($data['productSku'] ?? $data['sku'] ?? '');
        $dto->qty        = (int)($data['qty'] ?? 0);

        return $dto;
    }
}
