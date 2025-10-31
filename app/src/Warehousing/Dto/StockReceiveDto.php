<?php

namespace App\Warehousing\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class StockReceiveDto
{
    // Note that warehouse code and product sku are received via route parameter
    // and validated on route-level
    #[Assert\Positive]
    public int $qty;

}

