# Common client identity and change-event foundation

**Status:** Initial Laravel/MySQL foundation committed on the failover work branch. It is not yet a live cross-region replication or automatic failover implementation.

## Shared client identity

The existing `users` table is the canonical Nexora group account identity. NexISP subscriptions and Loopza orders already reference that same user table. New `client_service_identities` rows map one group user to a product-specific customer reference within an organisation:

- one user can have a NexISP reference and a separate Loopza reference;
- the product reference is unique within its organisation/product;
- the same user cannot accidentally receive duplicate identity rows for the same organisation/product;
- tenant/organisation boundaries are retained.

Do not create a second independent customer master list for a backup system. A disaster-recovery replica must replicate this canonical identity and its mappings consistently.

## Durable change outbox

`client_change_outbox` stores versioned client/service change events, unique event UUIDs, optional idempotency keys, product and tenant scope, payload, occurrence time, publication state and retry/error metadata.

`App\\Services\\ClientChangeOutbox` records events and returns the same event row for a replay with the same idempotency key and identical event. Reusing a key for a different event is rejected.

Callers must write the business record and outbox event inside the **same outer database transaction**. The outbox service uses a nested Laravel transaction, so it participates in the caller's transaction. This is essential: a committed subscription/payment/order change must not exist without its corresponding durable event.

Consumers must:
- process events in aggregate-version order where order matters;
- be idempotent by `event_uuid` or idempotency key;
- acknowledge only after the destination write is durable;
- retry transient failures with backoff and dead-letter persistent failures;
- preserve organisation, product, country and permission boundaries;
- never independently write to two active database primaries.

## Current implementation boundary

This commit adds the identity mapping, outbox table, model, service and focused feature tests. It does **not** yet automatically emit events from every billing, subscription, profile, case or payment workflow; it does not run a publisher/consumer; and it does not replicate data to a second region. Those are the next implementation stages. Do not describe the system as automatically synchronised or zero-downtime until those integrations, a compatible MySQL HA topology, failover fencing and recovery drills pass.
## Scheduled signed publisher

The Laravel application now has `App\Services\ClientChangeOutboxPublisher` and the Artisan command `php artisan nexora:outbox:publish`. A scheduler entry attempts publication every minute and prevents overlapping runs on the same shared scheduler lock.

Configure both environment values on the primary and keep the secret out of source control:

- `NEXORA_REPLICATION_URL`: HTTPS base URL of the receiving backup service.
- `NEXORA_REPLICATION_SECRET`: long random shared secret, identical on sender and receiver.

The publisher now enforces per-aggregate FIFO dispatch: it will not send a later outbox row while an earlier row for the same organisation/product/aggregate remains unpublished. This intentionally allows a failed earlier event to hold later events for that aggregate rather than silently applying changes out of order. Different aggregates can continue independently. FIFO is based on outbox insertion order, so callers must create aggregate events in the same order as their increasing `aggregate_version`; this does not replace receiver-side version-gap checks or a materializing consumer.\n\nThe publisher sends events to `POST /api/replication/events` with an event UUID idempotency key, timestamp and HMAC-SHA256 signature over the exact request body. It marks an event published only after a successful 2xx response. Failed attempts remain pending, increment an attempt counter, record a safe error summary and wait at least five minutes before retrying.

**Operational prerequisite:** Laravel's scheduler must actually be invoked by the host (Linux cron or Windows Task Scheduler running `php artisan schedule:run` every minute). Merely deploying the code does not start the scheduler.

**Important limitation:** the receiver endpoint, signature-verification middleware, inbox processor and destination-side application are not implemented by this publisher commit. Until those exist and are deployed on the backup, leave `NEXORA_REPLICATION_URL` unset. This publisher alone is not failover, database replication, or a disaster-recovery guarantee. Receiver processing must verify timestamp freshness and HMAC using constant-time comparison, persist each `event_uuid` once, and return 2xx only after durable inbox commit.
## Backup receiver inbox

The Laravel API now exposes `POST /api/replication/events` and persists authenticated events in `client_change_inbox`. The receiver checks a five-minute timestamp window, verifies HMAC-SHA256 against the exact raw request bytes with constant-time comparison, validates product/event fields, checks the idempotency header, and stores a unique event UUID with a hash of the original body.

An exact replay receives an accepted duplicate response without creating a second row. Reusing an event UUID with a different body returns HTTP 409. The receiver acknowledges only after the inbox transaction commits.

**Still not complete:** the inbox is durable receipt, not yet application of replicated state. A worker must consume events in aggregate-version order, apply them idempotently to the standby data store, and record processing outcomes. Do not treat inbox receipt as proof that subscriptions, cases, invoices, files, router state, or sessions are available on the backup.

The receiving inbox also enforces a unique non-null idempotency key in addition to the unique event UUID. A replay with the same event UUID and exact body is acknowledged as a duplicate; reusing an idempotency key for a different event UUID is rejected with HTTP 409. This prevents a sender retry or integration bug from creating two accepted inbox events for one business operation.
