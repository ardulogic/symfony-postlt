<?php

namespace App\Warehousing\Messages;

final class ReallocateStockJob
{
    public function __construct(

        public readonly string $id,

        /** @var string[] limit reallocation scan to these SKUs (optional, but faster) */
        public readonly array $affectedSkus = [],

    ) {}
}
