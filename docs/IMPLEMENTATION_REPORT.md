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
- Full suite: 79 tests, 249 assertions, pass.
- GitHub Actions [run 44995042659](https://github.com/netanywhere254-beep/Nexcorp/actions/runs/44995042659)
  passed all five jobs on commit `d16fd56`.
- CI proves checked-in build/tests pass; not payment verification or production deployment.

## Not yet implemented

- Payment initiation/verification/callbacks, invoices, receipts, credits,
  reconciliation, router provisioning, or customer-facing UI.
