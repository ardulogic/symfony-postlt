<?php
namespace App\Warehousing\Dto;

use Symfony\Component\Validator\Constraints as Assert;

#[Assert\Expression(
    // If you add more updatable fields later, extend this condition.
    "this.code !== null or this.name !== null",
    message: "Provide at least one field to update.",
    groups: ['update']
)]
final class WarehouseDto
{
    // Immutable: allowed only on create
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(max: 32, groups: ['create', 'update'])]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9._-]+$/',
        message: 'Code may contain A-Z, 0-9, dot, underscore, hyphen.',
        groups: ['create', 'update']
    )]
    public ?string $code = null;

    // Updatable
    #[Assert\NotBlank(groups: ['create'])]
    #[Assert\Length(min: 2, max: 128, groups: ['create','update'])]
    public ?string $name = null;

    public static function fromArray(array $data): self
    {
        $dto = new self();

        $dto->code = array_key_exists('code', $data) ? (string)$data['code'] : null;
        $dto->name = array_key_exists('name', $data) ? (string)$data['name'] : null;

        return $dto;
    }
}
