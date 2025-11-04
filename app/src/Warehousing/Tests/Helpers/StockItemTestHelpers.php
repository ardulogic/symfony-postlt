<?php

namespace App\Warehousing\Tests\Helpers;

trait StockItemTestHelpers
{
    /**
     * Receive stock into a warehouse via API and assert successful creation.
     * @throws \JsonException
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

    /**
     * Read a stock item via API and return the decoded JSON, asserting expected status.
     */
    protected function readStockItem(string $warehouseCode, string $sku, int $expectedStatus = 200): array
    {
        $this->client->request('GET', $this->url('stock_items_read', ['code' => $warehouseCode, 'sku' => $sku]));
        self::assertResponseStatusCodeSame($expectedStatus);
        return json_decode($this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

}
