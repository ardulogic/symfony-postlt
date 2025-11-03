<?php

namespace App\Orders\Enum;

enum OrderLineStatus: string
{
    case PENDING            = 'PENDING';
    case RESERVED           = 'RESERVED';
    case RESERVED_PARTIAL   = 'RESERVED_PARTIAL';
    case SHIPPED            = 'SHIPPED';
    case SHIPPED_PARTIAL    = 'SHIPPED_PARTIAL';
    case CANCELED           = 'CANCELED';
    case OUT_OF_STOCK       = 'OUT_OF_STOCK';
}
