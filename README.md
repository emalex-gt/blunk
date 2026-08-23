# Blunk

Blunk is a full-stack SaaS ERP/POS platform built with Laravel, React, TypeScript, and PostgreSQL. It is designed for tenant-aware retail operations that need point-of-sale workflows, inventory control, purchasing, branch operations, customer management, and Guatemala FEL support in one application.

## Tech Stack

| Area | Technologies |
| --- | --- |
| Backend | PHP 8.3, Laravel 12, Eloquent, Laravel Sanctum |
| Frontend | React 18, TypeScript, Inertia.js, Tailwind CSS |
| Database | PostgreSQL |
| Documents and imports | Laravel Excel, DomPDF, Cloudinary integration |
| Testing | PHPUnit, Laravel feature and unit tests |
| Tooling | Vite, Axios, Ziggy, Laravel Pint |

## Core Capabilities

- Multi-tenant business management with business-scoped data and subscription-aware tenant access.
- Branch-aware operations, inventory, product availability, price lists, and optional branch pricing.
- POS sales with receipts and invoice workflows, split payments, discounts, cash-register movements, and printable documents.
- Guatemala FEL integration through Digifact, including certification, document retrieval, retry/reconciliation paths, and tenant FEL settings.
- Inventory movements, stock policies, negative-stock configuration, product locations, purchases, and inter-branch transfers.
- Customer, supplier, category, brand, and product catalog management, including Excel product import support.
- Credit reservations that remain pending for invoicing, alongside accounts receivable, customer credit accounts, payments, allocations, and statements.
- Operational reporting with permission-controlled PDF and XLSX exports.
- Route and pre-sale workflows for zones, work days, visits, review, picking, and conversion support.

## Engineering Highlights

- **Transaction-safe POS mutations:** sale creation performs inventory, sale-line, payment, and cash-register changes inside database transactions. Product and pending-credit lines are locked before stock-affecting updates.
- **Persistent idempotency for critical operations:** `operation_idempotency_keys` stores scoped request hashes and outcomes. The reusable `IdempotencyService` serializes repeated requests with row locks to prevent duplicate execution.
- **Tenant and branch isolation:** routes and application queries derive the active business from authenticated context and scope operational records by `business_id`; branch-aware services resolve the active branch for stock and pricing behavior.
- **Inventory integrity controls:** inventory changes create stock movements, stock availability accounts for reservations, and tenant settings control whether negative stock is allowed.
- **Authorization at the route boundary:** modular features and permission middleware protect operational routes, with a permission catalog and role synchronization support.
- **Automated regression coverage:** the repository includes feature tests around POS/FEL flows, idempotency, inventory, credit operations, tenant reporting, exports, drafts, routes, and integrity auditing.

## Architecture Overview

Blunk is a Laravel monolith with a React/Inertia application layer. It is not presented as a microservices system.

```text
React + TypeScript UI
        |
        | Inertia.js page visits and props
        v
Laravel HTTP layer
  routes -> middleware -> controllers
        |
        +-> application services and support classes
        |     - POS and inventory services
        |     - idempotency and authorization helpers
        |     - FEL/Digifact provider services
        |     - reporting and export helpers
        |
        v
PostgreSQL
  tenant, branch, catalog, sales, inventory,
  cash, credit, FEL, routes, and audit records
```

The frontend lives under `resources/js`, while Laravel controllers, domain-oriented support classes, provider integrations, migrations, and tests live under `app`, `database`, and `tests`.

## Example Critical Flow: POS Sale

A representative POS sale follows this sequence:

1. The authenticated user reaches the POS through tenant, module, and permission middleware; the application resolves the active business and branch.
2. The request carries an idempotency key. `IdempotencyService` creates or locks a scoped operation record and replays a completed result instead of executing the same request twice.
3. Inside a database transaction, the sale flow locks each product, validates availability against the tenant stock policy, resolves the applicable price, and creates the sale and its line items.
4. Each sold line decreases branch inventory and records a `StockMovement`; invoiced credit-reservation lines are updated under lock.
5. The flow records payment rows and, when appropriate, a cash-register movement.
6. For credit receipts, an accounts-receivable charge is created in the same transaction. For credit invoices, FEL certification is attempted before the receivable charge is created; certification failures roll back the local transaction and create a durable reconciliation request outside it.
7. The completed operation is recorded against the idempotency key and the POS redirects with the appropriate receipt or FEL outcome.

## Testing

The application has Laravel feature and unit tests. The testing workflow is configured for PostgreSQL because migrations and critical workflows use PostgreSQL-specific behavior.

```bash
createdb blunk_test
cp .env.testing.example .env.testing
php artisan key:generate --env=testing
php artisan test --env=testing
```

Build the frontend with:

```bash
npm run build
```

Do not commit local `.env` or `.env.testing` files with credentials.

## Local Development

Prerequisites: PHP 8.3+, Composer, Node.js/npm, and PostgreSQL.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configure the PostgreSQL connection in `.env`, then run:

```bash
php artisan migrate
composer dev
```

`composer dev` starts the Laravel server, queue listener, log tail, and Vite development server. Use `npm run dev` when only the frontend dev server is needed.

## AI-Assisted Development

AI-assisted tools may be used to accelerate implementation, investigation, and documentation. Architectural decisions, code review, validation, testing, and responsibility for the resulting software remain with the developer.

## Project Status

Blunk is under active development. This repository documents the current application implementation and is not presented as an open-source product or a production deployment claim.
