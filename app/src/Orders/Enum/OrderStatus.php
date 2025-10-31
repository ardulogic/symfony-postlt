<?php

namespace App\Orders\Enum;

enum OrderStatus: string
{
    case PENDING             = 'PENDING';
    case RESERVED            = 'RESERVED';
    case PARTIALLY_RESERVED  = 'PARTIALLY_RESERVED';
    case SHIPPED             = 'SHIPPED';
    case CANCELED            = 'CANCELED';
}
