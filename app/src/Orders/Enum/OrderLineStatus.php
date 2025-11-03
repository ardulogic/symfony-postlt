<?php

namespace App\Orders\Enum;

enum OrderLineStatus: string
{
    case PENDING            = 'PENDING';
    case RESERVED           = 'RESERVED';
    case RESERVED_PARTIAL   = 'RESERVED_PARTIAL';
    case SHIPPED            = 'SHIPPED';
    case CANCELED           = 'CANCELED';
}
