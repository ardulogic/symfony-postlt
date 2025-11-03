# API Reference

All endpoints are prefixed with `/api`.

## Health
- `GET /api/health/live`
- `GET /api/health/ready`

## Products
- Create: `POST /api/products`
- Read: `GET /api/products/{sku}`
- List: `GET /api/products?page=1&per_page=20`
- Update: `PUT /api/products/{sku}`
- Delete: `DELETE /api/products/{sku}`

## Orders
- Create: `POST /api/orders/create`
- Read: `GET /api/orders/{number}`
- List: `GET /api/orders?page=1&per_page=20`

## Warehouses
- Create: `POST /api/warehouses`
- Read: `GET /api/warehouses/{code}`
- List: `GET /api/warehouses?page=1&per_page=20`
- Update: `PUT /api/warehouses/{code}`
- Delete: `DELETE /api/warehouses/{code}`

## Stock Items
- Read: `GET /api/warehouses/{code}/stock/{sku}`
- List: `GET /api/warehouses/stock?page=1&per_page=20`
- Receive: `POST /api/warehouses/{code}/stock/{sku}/receive`
  - Triggers `ReallocateStockJob` for the received SKU

## Stock Reservations
- Create: `POST /api/warehouses/stock/reservations`
- Read: `GET /api/warehouses/stock/reservations/{number}`
- List: `GET /api/warehouses/stock/reservations?page=1&per_page=20`
- Ship: `POST /api/warehouses/stock/reservations/{number}/ship`
- Cancel: `PUT /api/warehouses/stock/reservations/{number}`
  - Triggers `ReallocateStockJob`

## Messaging
Routing (conceptual):
```yaml
'App\\Orders\\Messages\\OrderCreatedMessage': async
'App\\Warehousing\\Messages\\StockReservationStatusChangedMessage': async
'App\\Warehousing\\Messages\\ReallocateStockJob': async
```

Run workers:
```bash
php bin/console messenger:consume async -vv --sleep=1 --time-limit=0 --memory-limit=-1
```


