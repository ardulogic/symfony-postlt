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

Environment notes:
- Set Redis URL (with password if enabled) and Messenger DSN, e.g.:
  - `REDIS_URL=redis://:password@redis:6379`
  - `MESSENGER_TRANSPORT_DSN=${REDIS_URL}/messages`

Setup:
1) Start containers
```bash
make up-dev
```
2) Install dependencies
```bash
make install-dev
```
3) Initialize database and seed demo data (products, warehouses, stock)
```bash
make fresh-seed-dev
```
4) Run the worker (process async messages)
```bash
make worker-dev
```

API base URL: `http://localhost:8080/api`

## Testing

Run the test suite:
```bash
make test-dev
```

Notes:
- Tests use the in-memory messenger transport to assert dispatches.
- Warehousing reallocation is exercised by cancelling reservations and by receiving stock.

## Full API Reference

See `API.md` for all endpoints, payloads, and examples.

### Health Checks

- **`GET /api/health/live`** - Liveness probe
- **`GET /api/health/ready`** - Readiness probe (checks DB and Redis)

### Orders Module

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

#### Read Order
**`GET /api/orders/{number}`**  
**Response:** `200 OK` with order JSON (includes lines, status)

### Products Module

#### Create Product
**`POST /api/products`**
```json
{
    "sku": "SKU-001",
    "name": "Product Name"
}
```
**Response:** `201 Created` with `Location` header

#### Read Product
**`GET /api/products/{sku}`**  
**Response:** `200 OK` with product JSON  
**Error:** `404 Not Found` if product doesn't exist

#### List Products
**`GET /api/products?page=1&per_page=20`**  
**Query Parameters:**
- `page` (optional, default: 1) - Page number
- `per_page` (optional, default: 20, max: 100) - Items per page

**Response:** `200 OK` with paginated list:
```json
{
    "data": [...],
    "meta": {
        "page": 1,
        "per_page": 20,
        "total": 100,
        "total_pages": 5
    }
}
```

#### Update Product
**`PUT /api/products/{sku}`**
```json
{
    "name": "Updated Name"
}
```
**Response:** `200 OK` with updated product  
**Error:** `404 Not Found` if product doesn't exist  
**Note:** At least one field (sku or name) must be provided

#### Delete Product
**`DELETE /api/products/{sku}`**  
**Response:** `204 No Content`  
**Error:** `404 Not Found` if product doesn't exist

### Warehousing Module

#### Stock Reservations

**Create Reservation**
**`POST /api/warehouses/stock/reservations`**
```json
{
    "number": "ORD-001",
    "lines": [
        {"productSku": "SKU-001", "qty": 2}
    ]
}
```
**Response:** `201 Created`  
**Triggers:** Dispatches `StockReservationStatusChangedMessage` with status

**Read Reservation**
**`GET /api/warehouses/stock/reservations/{number}`**  
**Response:** `200 OK` with reservation details (includes lines, status, quantities)

**List Reservations**
**`GET /api/warehouses/stock/reservations?page=1&per_page=20`**  
**Query Parameters:**
- `page` (optional, default: 1) - Page number
- `per_page` (optional, default: 20, max: 100) - Items per page

**Response:** `200 OK` with paginated list
```json
{
  "data": [
    { "number": "ORD-001", "status": "RESERVED", "lines": [/* ... */] },
    { "number": "ORD-002", "status": "RESERVED_PARTIAL", "lines": [/* ... */] }
  ],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 42,
    "total_pages": 3
  }
}
```

**Cancel Reservation**
**`PUT /api/warehouses/stock/reservations/{number}`**  
**Response:** `202 Accepted`  
**Triggers:** 
- Dispatches `StockReservationStatusChangedMessage` with `CANCELED` status
- Dispatches `ReallocateStockJob` for stock reallocation

**Ship Reservation**
**`POST /api/warehouses/stock/reservations/{number}/ship`**  
**Response:** `202 Accepted`  
**Triggers:** Dispatches `StockReservationStatusChangedMessage` with `SHIPPED` status

#### Stock Items

**Read Stock Item**
**`GET /api/warehouses/{code}/stock/{sku}`**  
**Response:** `200 OK` with stock item details (available, reserved, on-hand quantities)  
**Parameters:**
- `code` - Warehouse code (1-32 chars, alphanumeric)
- `sku` - Product SKU (1-64 chars, alphanumeric)

**List Stock Items**
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

**Receive Stock**
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

#### Warehouses

**Create Warehouse**
**`POST /api/warehouses`**
```json
{
    "code": "WH-001",
    "name": "Warehouse Name"
}
```
**Response:** `201 Created` with `Location` header

**Read Warehouse**
**`GET /api/warehouses/{code}`**  
**Response:** `200 OK` with warehouse JSON  
**Error:** `404 Not Found` if warehouse doesn't exist

**List Warehouses**
**`GET /api/warehouses?page=1&per_page=20`**  
**Query Parameters:**
- `page` (optional, default: 1) - Page number
- `per_page` (optional, default: 20, max: 100) - Items per page

**Response:** `200 OK` with paginated list (same format as products list)

**Update Warehouse**
**`PUT /api/warehouses/{code}`**
```json
{
    "name": "Updated Name"
}
```
**Response:** `200 OK` with updated warehouse  
**Error:** `404 Not Found` if warehouse doesn't exist  
**Note:** At least one field (code or name) must be provided

**Delete Warehouse**
**`DELETE /api/warehouses/{code}`**  
**Response:** `204 No Content`  
**Error:** `404 Not Found` if warehouse doesn't exist

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

- `DATABASE_URL`: PostgreSQL connection string
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

**Message Routing:**
```yaml
'App\Orders\Messages\OrderCreatedMessage': async
'App\Warehousing\Messages\StockReservationStatusChangedMessage': async
'App\Warehousing\Messages\ReallocateStockJob': async
```

### Running Message Workers

In production, you need to run Symfony Messenger workers to process queued messages:

```bash
# In container or local
php bin/console messenger:consume async -vv
```

For development with `sync://` transport, messages are processed immediately.

## Development Workflow

### Typical Development Flow

1. **Start development environment:**
   ```bash
   make up-dev
   ```

2. **Make code changes** in `app/src/`

3. **Run tests** to verify changes:
   ```bash
   make test-dev
   ```

4. **Test API manually:**
   - API available at: `http://localhost:8080/api`
   - Use curl, Postman, or any HTTP client

5. **Check logs** if issues arise:
   ```bash
   make logs-dev
   ```

### Database Migrations

When changing entities:

```bash
# Generate migration
php bin/console doctrine:migrations:diff

# Apply migration
php bin/console doctrine:migrations:migrate

# Or use make command
make fresh-diff-seed-dev  # Creates diff, migrates, and seeds
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

## Additional Resources

- **Symfony Documentation:** https://symfony.com/doc/current/
- **Symfony Messenger:** https://symfony.com/doc/current/messenger.html
- **Doctrine ORM:** https://www.doctrine-project.org/projects/doctrine-orm/en/current/index.html

## Troubleshooting

### Messages Not Processing

- Check Redis connection: `REDIS_URL` environment variable
- Verify worker is running: `php bin/console messenger:consume async`
- Check message routing in `messenger.yaml`

### Tests Failing

- Ensure fixtures are loaded: `make fresh-seed-dev`
- Check test transport is `in-memory://` in test environment
- Verify InMemoryTransport is accessible: `$this->c->get('messenger.transport.async')`

### Database Issues

- Reset database: `make fresh-seed-dev`
- Check migrations: `php bin/console doctrine:migrations:status`
- Verify database connection in `DATABASE_URL`
