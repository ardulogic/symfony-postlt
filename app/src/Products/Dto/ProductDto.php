<?php
namespace App\Products\Dto;

use Symfony\Component\Validator\Constraints as Assert;

#[Assert\Expression(
    "this.sku !== null or this.name !== null",
    message: "Provide at least one field to update.",
    groups: ['update']
)]
final class ProductDto
{
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(max: 64, groups: ['create','update'])]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9._-]+$/i',
        message: 'SKU may contain A-Z, 0-9, dot, underscore, hyphen.',
        groups: ['create','update']
    )]
    public ?string $sku = null;

    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(min: 2, max: 160, groups: ['create','update'])]
    public ?string $name = null;

    public static function fromArray(array $data): self
    {
        $dto = new self();

        $dto->sku = array_key_exists('sku', $data) ? (string)$data['sku'] : null;
        $dto->name = array_key_exists('name', $data) ? (string)$data['name'] : null;

        return $dto;
    }
}
