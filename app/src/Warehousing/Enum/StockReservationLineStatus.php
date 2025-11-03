<?php

namespace App\Warehousing\Enum;

enum StockReservationLineStatus: string
{
    case PENDING            = 'PENDING';
    case RESERVED           = 'RESERVED';
    case RESERVED_PARTIAL   = 'RESERVED_PARTIAL';

    case SHIPPED            = 'SHIPPED';
    const SHIPPED_PARTIAL   = 'SHIPPED_PARTIAL';

    case OUT_OF_STOCK       = 'OUT_OF_STOCK';
    case CANCELED           = 'CANCELED';

}
