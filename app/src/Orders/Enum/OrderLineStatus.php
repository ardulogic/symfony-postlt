<?php

namespace App\Orders\Enum;

enum OrderLineStatus: string
{
    case PENDING            = 'PENDING';            // created, not allocated yet
    case RESERVED_FULL      = 'RESERVED_FULL';      // qtyReserved == qtyOrdered
    case RESERVED_PARTIAL   = 'RESERVED_PARTIAL';   // 0 < qtyReserved < qtyOrdered
    case CANCELED           = 'CANCELED';           // line canceled (all reservations released)
    case SHIPPED            = 'SHIPPED';            // qtyShipped == qtyOrdered (final)
}
