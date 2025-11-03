<?php

namespace App\Warehousing\Enum;

enum StockReservationStatus: string
{
    case PENDING            = 'PENDING';
    case RESERVED           = 'RESERVED';
    case RESERVED_PARTIAL   = 'RESERVED_PARTIAL';
    case SHIPPED            = 'SHIPPED';

    case OUT_OF_STOCK       = 'OUT_OF_STOCK';
    case CANCELED           = 'CANCELED';

}
