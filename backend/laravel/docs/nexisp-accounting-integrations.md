# NexISP accounting integrations

NexISP provides an organization-owned accounting integration boundary. Each ISP can connect its own bookkeeping account, map NexISP financial categories to the accounting provider's chart of accounts, and synchronize records without sharing credentials or financial data with another tenant.

## Provider roadmap

The provider catalog is deliberately explicit about availability. Zoho Books is the first planned OAuth2 connector, followed by QuickBooks Online, Xero and Sage Accounting. CSV export is also planned as a provider-neutral fallback. A provider must not be presented as connected or available until its OAuth authorization, token refresh, account selection, export worker and reconciliation tests are implemented.

The `AccountingProvider` contract defines the adapter boundary. Provider adapters must:
- use OAuth2 authorization-code flow with one-time, short-lived, server-stored state bound to the initiating user and organization;
- validate redirect URI and provider identity, request least-privilege scopes, and never accept access tokens from browser request bodies;
- encrypt access and refresh tokens at rest, exclude them from API responses/logs, and support revocation and refresh-token rotation;
- respect provider rate limits and retry transient failures with bounded exponential backoff;
- use stable idempotency keys or provider-side duplicate checks before creating remote records;
- retain remote IDs and sync outcomes for reconciliation.

## Current implementation boundary

This increment creates:
- tenant-scoped accounting connections with encrypted OAuth token fields;
- tenant-scoped chart-of-account mappings;
- sync runs and sync outbox items with unique idempotency keys;
- a billing-authorized endpoint to list provider options and connected accounts;
- a billing-authorized endpoint to queue an invoice export;
- audit events for queued exports;
- a provider adapter interface.

The export endpoint only queues an outbox item. It does not yet call Zoho, QuickBooks, Xero, Sage or CSV, and it must not be described to users as a completed live integration. Provider OAuth callback routes, provider-specific adapters, background workers, remote reconciliation, credit notes, expense/bill import and bank-feed synchronization remain follow-up work.

## Account mapping

Before an invoice can be queued, each connection must have enabled mappings for:
- `accounts_receivable`
- `subscription_revenue`

Mappings are organization-owned and tied to a specific accounting connection. Do not infer or silently create external chart-of-account mappings.

## Security and bookkeeping correctness

- Every query is scoped by the active server-selected organization; never trust a tenant ID from the browser.
- Only users authorized by the organization's `manageBilling` policy can inspect connections or queue exports.
- OAuth tokens are encrypted using Laravel's application encryption key and hidden from model serialization. Keep `APP_KEY` backed up and rotate it only with a planned re-encryption procedure.
- Sync item uniqueness is enforced per organization, connection and idempotency key. Retrying the same invoice export returns the existing item.
- Store provider errors only after removing tokens, authorization headers, customer secrets and other sensitive payloads.
- Do not automatically mark a NexISP invoice paid from an accounting export response. Payment reconciliation must use verified payment records and explicit matching rules.
- Never overwrite accounting records silently. Updates, voids, credit notes and reversals require explicit mapping and audit behavior.

## API routes

All routes require an authenticated, verified user and active organization:
- `GET /nexisp/accounting/providers`
- `GET /nexisp/accounting/connections`
- `POST /nexisp/accounting/connections/{connectionId}/invoices/{invoiceId}/export`

## Local validation

From `backend/laravel`:

```sh
php artisan migrate
php artisan test --filter=AccountingSyncServiceTest
php artisan test
```
