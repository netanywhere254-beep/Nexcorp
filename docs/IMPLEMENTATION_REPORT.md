# Nexora Group — Implementation Report

Last updated: 2026-11-22
Primary branch inspected: `main` at `0fc7c19`
Working branch for this report: `implementation/nexisp-subscriptions`

## Implemented on this branch

- NexISP subscription lifecycle foundation (tenant-scoped):
  - Migration adding `idempotency_key` to `nexisp_subscriptions`.
  - `NexispSubscriptionController` with list/create/show/activate/suspend/cancel.
  - Server-calculated price/currency/plan snapshot from the selected active plan;
    client-supplied price/status fields are ignored.
  - Idempotent create by per-organization `idempotency_key`.
  - Tenant-scoped plan validation, active-user/active-plan checks, status
    transition guards, and audit events.
  - Feature tests covering pricing integrity, idempotency, tenant isolation,
    invalid transitions, and role authorization.

## Verification

- `php -l` passed on changed PHP files.
- `phpunit --filter NexispSubscriptionTest`: 5 tests, 29 assertions, pass.
- Full suite / GitHub CI: pending.

## Not yet implemented

- Payment initiation/verification/callbacks, invoices, receipts, credits,
  reconciliation, router provisioning, or customer-facing UI.
