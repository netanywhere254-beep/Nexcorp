# Nexora commerce foundation

Status: schema and Eloquent model baseline only. This is not a checkout, billing, payment integration, or service-provisioning release.

## Product boundaries

- NexISP bandwidth plans and subscriptions are separate from Loopza products and orders.
- Each commercial record is owned by an organization. Every read/write endpoint must derive the active organization from the authenticated server-side session and verify active membership; never trust a client-supplied organization ID by itself.
- Payment transactions are shared infrastructure, but each transaction is polymorphically attached to exactly one payable record. The tenant and user IDs are stored on the transaction for audit and reconciliation.
- Monetary values use fixed-precision decimal columns and an explicit ISO currency code. New Kenyan tenant data defaults to KES; cross-border organizations must set their actual currency.
- Order items retain SKU, title, quantity and unit-price snapshots so later product edits do not rewrite a historical receipt.
- NexISP subscriptions retain a plan snapshot so later plan changes do not rewrite a customer's purchased terms.

## Tables introduced

| Table | Purpose |
|---|---|
| `nexisp_bandwidth_plans` | Tenant-owned ISP plan catalogue |
| `nexisp_subscriptions` | Tenant/customer subscription records, initially pending payment |
| `loopza_products` | Tenant-owned product catalogue and stock quantity |
| `loopza_orders` | Tenant/customer orders, initially pending and unpaid |
| `loopza_order_items` | Immutable commercial line-item snapshots |
| `payment_transactions` | Shared provider transaction record linked to a NexISP subscription or Loopza order |

## Non-negotiable workflow rules for the next service layer

1. Public product and plan listings return only active records from the user's verified active organization.
2. A requested plan/product ID is always reloaded within that organization. An ID from another tenant must behave as not found or forbidden; it must never be purchasable.
3. The server calculates prices from the stored catalogue. Client-supplied totals, currency, tenant IDs, status, paid flags, or stock values are ignored.
4. Creating an order/subscription does not activate a service or complete a purchase. Both remain unpaid/pending until a trusted payment-provider verification succeeds.
5. Provider callbacks must be authenticated, signature-verified, amount/currency/payable/tenant matched, and idempotent. A browser redirect or client assertion is not proof of payment.
6. The payment transition and associated business-state transition must be atomic and concurrency-safe.
7. Loopza inventory must be locked and checked inside the order transaction. Deduct stock only after verified payment, with a unique fulfilment/debit guard to prevent duplicate callbacks from decrementing twice.
8. NexISP network provisioning occurs only after verified payment and a successful subscription activation transaction. Provisioning failure must be retryable and visible; it must not falsify payment state.
9. Every privileged catalogue, price, stock, payment, refund, activation and fulfilment change must create an audit event.
10. Public registration remains account-only. Organization membership and administrator authority continue through separately controlled workflows.

## Deliberate omissions

This baseline does not yet add HTTP routes/controllers, payment-provider adapters, checkout services, stock reservation/expiry, refunds, invoices, customer-facing UI, network provisioning, production seeders, or the NestJS/PostgreSQL equivalent. Do not advertise those features as implemented. Add each with tests and matching standby contracts before calling the product commercially ready.

## Seed data policy

Do not put sample products or plans in the production seeder. Add separate local/demo seeders that first create or find explicitly named demo organizations, use stable organization-scoped codes/SKUs and idempotent upserts, and clearly label sample data. NetPulse must be provisioned as a real NexISP tenant through the organization onboarding workflow, not silently created as a global default by a generic seeder.
