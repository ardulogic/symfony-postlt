# PostLit - E-Commerce Order Management System

## About

PostLit is a Symfony-based, modular application for orders and warehousing, designed to demonstrate clean boundaries and asynchronous communication without going "full microservices". Each module has its own controllers, services, entities, and messaging handlers, and communicates via Symfony Messenger (Redis) instead of direct service calls.

Why this structure:
- Decoupled modules model real-world teams and ownership without operational overhead of many deployables.
- Async messaging keeps modules independent in time and failure modes, while still simple to develop locally.
- Orders do not compute availability; Warehousing is the source of truth for reservation and shipment states.

Intentional design choices:
- Single-warehouse allocation per SKU line: the system does not split a single order line across multiple warehouses. Reasons:
  - Lower total shipping and handling fees by avoiding split shipments per line.
  - Simpler customer experience (one parcel per line reduces tracking complexity).
  - Reduced risk of partial shipments arriving out of order and causing support churn.
  - Clearer stock movements and easier reconciliation for finance/ops.
  - Keeps allocation logic straightforward for this demo; cross-warehouse splitting can be added later if desired.

Messaging:
- Orders → Warehousing: OrderCreatedMessage (create reservations)
- Warehousing → Orders: StockReservationStatusChangedMessage (sync statuses)
- Reallocation: ReallocateStockJob queued on cancels and stock receipts

## Quick Start

Prerequisites:
- Docker + Docker Compose v2

Setup:
1) Build and start containers
```bash
make up-dev
```

2) Create required directories
```bash
make prepare-dirs-dev

```
3) Install composer dependencies
```bash
make install-dev
```

4) Initialize database and seed demo data (products, warehouses, stock)
```bash
make fresh-seed-dev
```

5) Run the worker which processes redis queues
```bash
make worker-dev
```

Thats it!

API pulse URL: `http://localhost:8080/api/health/ready`

## Testing

Run the test suite:
```bash
make test-dev
```

Notes:
- Tests use the async in-memory messenger transport to assert dispatches.
- Warehousing reallocation is exercised by cancelling reservations or receiving stock.

## Full API Reference

See [`API.md`](docs/API.md) for all endpoints, payloads, and examples.

### Health Checks

- **`GET /api/health/live`** - Liveness probe
- **`GET /api/health/ready`** - Readiness probe (checks DB and Redis)

### Main API endpoints

#### List Stock Items
**`GET /api/warehouses/stock?page=1&per_page=20`**  
**Query Parameters:**
- `page` (optional, default: 1) - Page number
- `per_page` (optional, default: 20, max: 100) - Items per page

**Response:** `200 OK` with paginated list
```json
{
  "data": [
    { "warehouse": { "code": "WARE-EU-1" }, "productSku": "SKU-001", "onHandQty": 2, "reservedQty": 0 },
    { "warehouse": { "code": "WARE-EU-2" }, "productSku": "SKU-003", "onHandQty": 1, "reservedQty": 0 }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 10,
    "total_pages": 1
  }
}
```

#### Create Order
**`POST /api/orders/create`**
```json
{
    "number": "ORD-001",
    "lines": [
        {"productSku": "SKU-001", "qty": 2},
        {"productSku": "SKU-002", "qty": 1}
    ]
}
```
**Response:** `201 Created` with `Location` header  
**Triggers:** Dispatches `OrderCreatedMessage` → Warehousing creates stock reservation

- Make sure your worker is running: `make worker-dev` !

#### Read Order
**`GET /api/orders/{number}`**  
**Response:** `200 OK` with order JSON (includes lines, status)

- When order is allocated it will be updated via queue.

#### List Reservations
**`GET /api/warehouses/stock/reservations?page=1&per_page=20`**  
**Query Parameters:**
- `page` (optional, default: 1) - Page number
- `per_page` (optional, default: 20, max: 100) - Items per page

**Response:** `200 OK` with paginated list
```json
{
  "data": [
    { "number": "ORD-001", "status": "RESERVED", "lines": [ ] },
    { "number": "ORD-002", "status": "RESERVED_PARTIAL", "lines": [ ] }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 42,
    "total_pages": 3
  }
}
```

#### Cancel Reservation
**`PUT /api/warehouses/stock/reservations/{number}`**  
**Response:** `202 Accepted`  
**Triggers:** 
- Dispatches `StockReservationStatusChangedMessage` with `CANCELED` status
- Dispatches `ReallocateStockJob` for stock reallocation

#### Ship Reservations
**`POST /api/warehouses/stock/reservations/{number}/ship`**  
**Response:** `202 Accepted`  
**Triggers:** Dispatches `StockReservationStatusChangedMessage` with `SHIPPED` status

#### Receive Stock
**`POST /api/warehouses/{code}/stock/{sku}/receive`**
```json
{
    "qty": 100
}
```
**Response:** `201 Created` - Adds stock to warehouse  
**Parameters:**
- `code` - Warehouse code (1-32 chars, alphanumeric)
- `sku` - Product SKU (1-64 chars, alphanumeric)
**Triggers:** Dispatches `ReallocateStockJob` for the received SKU to reattempt allocations

## Testing

The test suite follows a **decoupled testing strategy** where Orders and Warehousing tests verify message dispatch independently without checking cross-module side effects. Tests use `InMemoryTransport` to verify messages in isolation.

**Run tests:**
```bash
make test-dev
```

**Test configuration:** The test environment uses `in-memory://` transport (configured in `config/packages/messenger.yaml`) instead of Redis, allowing tests to inspect dispatched messages without external dependencies.

## Configuration

### Environment Variables

Required environment variables (set in `.env` or docker-compose):

- `DATABASE_URL`: PostgreSQL connection string, automatically passed via docker
- `REDIS_URL`: Redis connection string (for cache and sessions)
- `MESSENGER_TRANSPORT_DSN`: Redis connection for message queue (defaults to `REDIS_URL`)

### Messenger Configuration

**Production/Development:**
- Transport: Redis (`redis://`)
- Messages are processed asynchronously by workers

**Test Environment:**
- Transport: In-memory (`in-memory://`)
- Messages are collected but not processed automatically
- Allows tests to verify message dispatch

### Running Message Workers

In production, you need to run Symfony Messenger workers to process queued messages:

```bash
# In container or local
make worker-dev
```
## Development Workflow

1. **See all make commands:**
   ```bash
   make up-dev
   ```

### Database Migrations

When changing entities:

```bash
# Generate migration
php bin/console doctrine:migrations:diff

# Apply migration
php bin/console doctrine:migrations:migrate

# Or use make command which !purges all data and seeds
make fresh-diff-seed-dev  # Creates diff, migrates, purges and seeds
```

## Order Lifecycle Example

### Complete Flow

1. **Create Order:**
   ```
   POST /api/orders/create
   → Order saved in database
   → OrderCreatedMessage dispatched to queue
   → Order status: PENDING
   ```

2. **Message Processing:**
   ```
   OrderCreatedMessage consumed by CreateStockReservationHandler
   → Stock reservation created
   → Stock allocated (if available)
   → StockReservationStatusChangedMessage dispatched
   → Reservation status: RESERVED (or RESERVED_PARTIAL, OUT_OF_STOCK)
   ```

3. **Status Update:**
   ```
   StockReservationStatusChangedMessage consumed by UpdateOrderStatusHandler
   → Order status updated to match reservation status
   → Order lines updated with quantities (qtyReserved, qtyShipped) and line statuses
   ```

4. **Ship Order:**
   ```
   POST /api/warehouses/stock/reservations/{number}/ship
   → Reservation shipped
   → StockReservationStatusChangedMessage dispatched (SHIPPED) with updated line quantities
   → Order status updated to SHIPPED
   → Order lines updated with qtyShipped values
   ```

### Status Transitions

```
PENDING → RESERVED (when stock available)
PENDING → RESERVED_PARTIAL (when partial stock available)
PENDING → OUT_OF_STOCK (when no stock available)
RESERVED → SHIPPED (when reservation shipped)
RESERVED → CANCELED (when reservation cancelled)
RESERVED_PARTIAL → SHIPPED (when reservation shipped)
RESERVED_PARTIAL → CANCELED (when reservation cancelled)
OUT_OF_STOCK → RESERVED (when stock becomes available via reallocation)
```

## Troubleshooting

### Messages Not Processing

- Check Redis connection: `REDIS_URL` environment variable
- Verify worker is running: `php bin/console messenger:consume async`
- Check message routing in `messenger.yaml`

### Database Issues

- Reset database: `make fresh-seed-dev`
- Check migrations: `php bin/console doctrine:migrations:status`
- Verify database connection in `DATABASE_URL`
