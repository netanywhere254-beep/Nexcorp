# NexISP customer, billing and callback storage

This migration adds standalone ISP customer records, invoices and encrypted payment callback intake. Customers do not need a Nexora login.

## Data protection

- Customer phone numbers use Laravel's encrypted cast at rest.
- A normalized HMAC-SHA256 blind index supports same-tenant duplicate detection without indexing plaintext phone numbers.
- The blind-index key is configured as `NEXISP_PHONE_HASH_KEY`. Set it to a dedicated, high-entropy secret in every environment and keep it stable. Do not rotate it without a planned re-index of existing customer records.
- Payment callback payloads use Laravel's `encrypted:array` cast and are hidden from model serialization.
- Callback deduplication is enforced by provider plus payload hash.
- Payment idempotency keys are unique within an organization, rather than globally.
- Invoice/customer and invoice/subscription references are constrained to the same organization at database level.

## Migration and tests

From `backend/laravel`:

```sh
php artisan migrate
php artisan test --filter=NexispCustomerSchemaTest
php artisan test
```

The rollback of this migration intentionally fails if customer-only payment transactions with a null `user_id` have already been created. Reassign or export those records before rolling back because the previous schema required a user.

## Integration boundary

This change adds schema and Eloquent models only. It does not yet expose public customer, invoice or payment callback endpoints. Callback handlers must verify provider signatures, enforce amount/currency/reference matching, apply replay protection, and process each callback idempotently inside a database transaction. All customer, invoice and subscription queries must be scoped by the authenticated organization; never trust an organization ID supplied by the browser.
