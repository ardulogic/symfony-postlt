<?php

namespace App\Warehousing\Tests\Helpers;

trait StockItemTestHelpers
{
    /**
     * Receive stock into a warehouse via API and assert successful creation.
     */
    protected function receiveStock(string $warehouseCode, string $sku, int $qty): void
    {
        $this->client->request(
            'POST',
            $this->url('stock_items_receive', ['code' => $warehouseCode, 'sku' => $sku]),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['qty' => $qty], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
    }

}
