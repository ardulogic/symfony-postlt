# API Reference

All endpoints are prefixed with `/api`.

## Health
- `GET /api/health/live`
- `GET /api/health/ready`

## Warehouses

**Create Warehouse**  
`POST /api/warehouses`
```json
{
  "code": "WARE-001",
  "name": "Warehouse Name"
}
```
Response: `201 Created`

**Read Warehouse**  
`GET /api/warehouses/{code}`  
Response: `200 OK`

**List Warehouses**  
`GET /api/warehouses?page=1&per_page=20`  
Response: `200 OK` with paginated data + meta

**Update Warehouse**  
`PUT /api/warehouses/{code}`
```json
{
  "name": "Updated Name"
}
```
Response: `200 OK`

**Delete Warehouse**  
`DELETE /api/warehouses/{code}`  
Response: `204 No Content`

## Stock Items

**Read Stock Item**  
`GET /api/warehouses/{code}/stock/{sku}`  
Response: `200 OK` with stock item details (onHandQty, reservedQty, available)

**List Stock Items**  
`GET /api/warehouses/stock?page=1&per_page=20`  
Response: `200 OK` with paginated list

**Receive Stock**  
`POST /api/warehouses/{code}/stock/{sku}/receive`
```json
{
  "qty": 100
}
```
Response: `201 Created` with `Location` header  
Triggers: Dispatches `ReallocateStockJob` for the received SKU

## Orders

**Create Order**  
`POST /api/orders/create`
```json
{
  "number": "ORD-001",
  "lines": [
    {"productSku": "SKU-001", "qty": 2},
    {"productSku": "SKU-002", "qty": 1}
  ]
}
```
Response: `201 Created` with `Location` header  
Triggers: Dispatches `OrderCreatedMessage` → Warehousing creates stock reservation

**Read Order**  
`GET /api/orders/{number}`  
Response: `200 OK` with order JSON (includes lines, status)

**List Orders**  
`GET /api/orders?page=1&per_page=20`  
Response: `200 OK` with paginated list

Messages:
- Dispatches `OrderCreatedMessage` on create
- Consumes `StockReservationStatusChangedMessage` to sync order and line statuses

## Stock Reservations

**Create Reservation**  
`POST /api/warehouses/stock/reservations`
```json
{
  "number": "ORD-001",
  "lines": [
    {"productSku": "SKU-001", "qty": 2}
  ]
}
```
Response: `201 Created`  
Triggers: Dispatches `StockReservationStatusChangedMessage` with status

**Read Reservation**  
`GET /api/warehouses/stock/reservations/{number}`  
Response: `200 OK` with reservation details (includes lines, status, quantities)

**List Reservations**  
`GET /api/warehouses/stock/reservations?page=1&per_page=20`  
Response: `200 OK` with paginated list

**Ship Reservation**  
`POST /api/warehouses/stock/reservations/{number}/ship`  
Response: `202 Accepted`  
Triggers: Dispatches `StockReservationStatusChangedMessage` with `SHIPPED` status

**Cancel Reservation**  
`PUT /api/warehouses/stock/reservations/{number}`  
Response: `202 Accepted`  
Triggers: 
- Dispatches `StockReservationStatusChangedMessage` with `CANCELED` status
- Dispatches `ReallocateStockJob` for stock reallocation

## Products

**Create Product**  
`POST /api/products`
```json
{
  "sku": "SKU-001",
  "name": "Product Name"
}
```
Response: `201 Created` with `Location` header

**Read Product**  
`GET /api/products/{sku}`  
Response: `200 OK` with product JSON

**List Products**  
`GET /api/products?page=1&per_page=20`  
Response: `200 OK` with paginated data + meta

**Update Product**  
`PUT /api/products/{sku}`
```json
{
  "name": "Updated Name"
}
```
Response: `200 OK`

**Delete Product**  
`DELETE /api/products/{sku}`  
Response: `204 No Content`

## Messaging
Routing (conceptual):
```yaml
'App\\Orders\\Messages\\OrderCreatedMessage': async
'App\\Warehousing\\Messages\\StockReservationStatusChangedMessage': async
'App\\Warehousing\\Messages\\ReallocateStockJob': async
```

Run workers:
```bash
php bin/console messenger:consume --all --keepalive --sleep=1 -vv
```
