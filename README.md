# PostLit - E-Commerce Order Management System

A Symfony-based microservices-style application for managing orders and warehouse inventory with decoupled modules communicating via asynchronous messaging.

## Table of Contents

- [Architecture Overview](#architecture-overview)
- [System Modules](#system-modules)
- [Messaging Architecture](#messaging-architecture)
- [Prerequisites](#prerequisites)
- [Quick Start](#quick-start)
- [API Endpoints](#api-endpoints)
- [Testing](#testing)
- [Configuration](#configuration)
- [Development Workflow](#development-workflow)

## Architecture Overview

This application follows a **decoupled, message-driven architecture** where two main modules communicate asynchronously:

- **Products Module**: Handles product data, decoupled from the rest
- **Orders Module**: Handles order creation and lifecycle
- **Warehousing Module**: Manages stock reservations, inventory, and fulfillment

The modules are **completely isolated** - they don't share entities or directly call each other's services. All communication happens through **Redis-based message queues** using Symfony Messenger.

### Key Design Principles

1. **Decoupling**: Modules only communicate via messages, not direct service calls
2. **Asynchronous Processing**: All inter-module communication is queued
3. **Status Synchronization**: Order status is driven by Warehousing, not computed internally
4. **Single Source of Truth**: Warehousing is the authority for reservation status

## System Modules

### Orders Module (`src/Orders/`)

**Responsibilities:**
- Order creation and management
- Order status tracking (received from Warehousing via messages)
- Order line items management

**Key Components:**
- `OrderController`: HTTP endpoints for order operations
- `OrderService`: Business logic for order operations
- `OrderCreatedMessage`: Message dispatched when an order is created
- `UpdateOrderStatusHandler`: Handles status updates from Warehousing

**Status Flow:**
- Orders start with `PENDING` status
- All subsequent status updates (order and line-level) come from Warehousing messages
- Order does NOT compute its own status
- Order lines receive quantities (qtyReserved, qtyShipped) and statuses from Warehousing

### Warehousing Module (`src/Warehousing/`)

**Responsibilities:**
- Stock reservation management
- Inventory allocation and tracking
- Stock reservation lifecycle (create, cancel, ship)
- Stock reallocation when reservations are cancelled or when stock is received

**Key Components:**
- `StockReservationController`: HTTP endpoints for reservation operations
- `StockReservationService`: Business logic for reservations
- `StockReservationStatusChangedMessage`: Message dispatched on status changes
- `CreateStockReservationHandler`: Handles order creation messages from Orders module
- `StockAllocator`: Intelligent stock allocation across warehouses
- `StockShipper`: Handles shipping operations

**Stock Allocation Strategy:**
- Prefers single-warehouse allocation when possible
- FIFO (First In First Out) for waiting reservations
- Automatic reallocation when stock becomes available (via cancellation or stock receipts)

## Messaging Architecture

### Message Flow

```
┌─────────────────┐                    ┌──────────────────┐
│  Orders Module  │                    │ Warehousing      │
│                 │                    │ Module           │
└────────┬────────┘                    └────────┬─────────┘
         │                                      │
         │  OrderCreatedMessage                │
         │─────────────────────────────────────>│
         │                                      │
         │                                      │ Create Stock Reservation
         │                                      │
         │                                      │
         │  StockReservationStatusChangedMessage│
         │<─────────────────────────────────────│
         │                                      │
         │ Update Order Status                  │
         │                                      │
```

### Message Types

#### OrderCreatedMessage (Orders → Warehousing)

**Dispatched when:** An order is successfully created

**Payload:**
```php
{
    "orderNumber": "ORD-001",
    "status": "PENDING",
    "lines": [
        {"productSku": "SKU-001", "qtyOrdered": 2},
        {"productSku": "SKU-002", "qtyOrdered": 1}
    ]
}
```

**Handler:** `CreateStockReservationHandler` in Warehousing module
**Action:** Creates a stock reservation with the specified SKUs and quantities

#### StockReservationStatusChangedMessage (Warehousing → Orders)

**Dispatched when:** 
- A stock reservation is created (after allocation)
- A stock reservation is cancelled
- A stock reservation is shipped

**Payload:**
```php
{
    "reservationNumber": "ORD-001",
    "status": "RESERVED", // RESERVED, RESERVED_PARTIAL, SHIPPED, CANCELED, OUT_OF_STOCK
    "lines": [
        {
            "productSku": "SKU-001",
            "qtyOrdered": 2,
            "qtyReserved": 2,
            "qtyShipped": 0,
            "status": "RESERVED"
        },
        {
            "productSku": "SKU-002",
            "qtyOrdered": 1,
            "qtyReserved": 0,
            "qtyShipped": 0,
            "status": "OUT_OF_STOCK"
        }
    ]
}
```

**Handler:** `UpdateOrderStatusHandler` in Orders module
**Action:** 
- Updates the order status to match the reservation status
- Updates order lines with quantities (qtyReserved, qtyShipped) and line-level statuses from warehousing
- Orders module does not recompute statuses - all status updates come from warehousing messages

### Status Mapping

Warehousing Status → Order Status:
- `RESERVED` → `RESERVED`
- `RESERVED_PARTIAL` → `RESERVED_PARTIAL`
- `SHIPPED` → `SHIPPED`
- `CANCELED` → `CANCELED`
- `OUT_OF_STOCK` → `OUT_OF_STOCK`
- `PENDING` → `PENDING`

## Prerequisites

- **Docker Engine** + **Docker Compose v2**
- Linux: Install Docker CE and Docker Compose:
  ```bash
  sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  sudo systemctl enable --now docker
  ```
- **(Optional)** Add your user to the `docker` group:
  ```bash
  sudo usermod -aG docker "$USER"
  newgrp docker
  ```

## Quick Start

### Development Environment

> **Important:** Do **not** run `make` commands with `sudo`. The Makefile auto-detects if your user can talk to Docker and falls back to `sudo docker` when needed.

#### Initial Setup

1. **Build and start the containers:**
   ```bash
   make up-dev
   ```
   This will build PHP-FPM, Nginx, PostgreSQL, and Redis containers. Initial build may take several minutes.

2. **Install dependencies:**
   ```bash
   make install-dev
   ```

3. **Create database and seed with demo data:**
   ```bash
   make fresh-seed-dev
   ```
   This will:
   - Drop existing database (if any)
   - Create new database
   - Run migrations
   - Load test fixtures (warehouses, products, stock items)

4. **Run tests:**
   ```bash
   make test-dev
   ```

#### Common Development Commands

- **Inspect container status:**
  ```bash
  make ps-dev
  ```

- **View logs:**
  ```bash
  make logs-dev
  ```

- **Open shell in container:**
  ```bash
  make sh-dev
  ```

- **Stop containers:**
  ```bash
  make down-dev
  ```

- **Clear Symfony cache:**
  ```bash
  make cache-clear
  ```

- **Check health endpoint:**
  ```bash
  make health
  ```

### Production Environment

Use the same commands without the `-dev` suffix:

```bash
make up           # Start production stack
make fresh-seed    # Create and seed database
make test         # Run tests
make down         # Stop stack
```

## API Endpoints

All endpoints are prefixed with `/api`.

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
