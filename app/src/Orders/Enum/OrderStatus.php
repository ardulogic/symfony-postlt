<?php

namespace App\Orders\Enum;

enum OrderStatus: string
{
    case PENDING             = 'PENDING';
    case RESERVED            = 'RESERVED';
    case PARTIALLY_RESERVED  = 'RESERVED_PARTIAL';
    case SHIPPED             = 'SHIPPED';
    case OUT_OF_STOCK        = 'OUT_OF_STOCK';
    case CANCELED            = 'CANCELED';
}
