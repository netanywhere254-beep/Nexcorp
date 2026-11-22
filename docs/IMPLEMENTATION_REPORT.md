# Nexora Group — Implementation Report

Last updated: 2026-11-22
Primary branch inspected: `main` at `0fc7c19`
Working branch for this report: `implementation/nexisp-payment-intents`

## Implemented on this branch

- NexISP payment intent foundation (tenant-scoped):
  - `PaymentTransactionController` with `GET/POST` endpoints for listing/showing
    payments and creating a payment intent for a subscription.
  - `PaymentIntentService` creates intents with server-calculated amount/currency
    from the subscription; client amount/currency/status are ignored.
  - Idempotent intent creation by idempotency key; payable mismatch rejected.
  - `PaymentProviderVerifier` contract and `verifyWithProvider()` service method:
    marks verified only when verifier confirms success and amount/currency match;
    mismatches/failures are recorded as failed and audited. Verified status is
    idempotent.
  - Authorization: `manageBilling` required for payment endpoints.
  - Audit events: `payment.intent_created`, `payment.verified`,
    `payment.verification_failed`, `payment.verification_mismatch`.

## Verification

- `php -l` passed on changed PHP files.
- `phpunit --filter NexispPaymentIntentTest`: 5 tests, 24 assertions, pass.
- Full suite / GitHub CI: pending.

## Not yet implemented

- Real payment provider adapters (M-Pesa, etc.), signed/verified public callback
  endpoints, invoice/receipt generation, reconciliation, refunds/credits,
  provisioning triggers after verified payment, or UI.
- This branch does not claim payment settlement or provider verification.
