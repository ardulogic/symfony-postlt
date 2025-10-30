<?php

namespace App\Warehousing\Factory;

use App\Warehousing\Dto\WarehouseDto;
use App\Warehousing\Entity\Warehouse;

/**
 * Factory is a layer between DTO and Entity by current symfony conventions
 * This conversion happens in several places, so it's good to have a class for clean structure
 */
class WarehouseFactory
{
    public function fromDto(WarehouseDto $dto): Warehouse
    {
        $Warehouse = new Warehouse();

        return $this->updateFromDto($Warehouse, $dto);
    }

    public function updateFromDto(Warehouse $Warehouse, WarehouseDto $dto): Warehouse
    {
        if ($dto->name !== null) $Warehouse->setName($dto->name);
        if ($dto->code !== null) $Warehouse->setCode($dto->code);

        return $Warehouse;
    }
}
