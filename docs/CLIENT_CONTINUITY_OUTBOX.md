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

`App\\Services\\ClientChangeOutbox` records events and returns the same event row for a replay with the same idempotency key and identical event. Reusing a key for a different event is rejected, including when the same key is presented under a different organisation or user. An idempotency replay is accepted only when the tenant/user ownership and event identity match the original record.

Callers must write the business record and outbox event inside the **same outer database transaction**. The outbox rejects non-increasing aggregate versions, and the outbox/inbox schemas add unique aggregate-version indexes. Tenant-scoped service-customer identity events must include their organisation ID and the next monotonically increasing version for that product/aggregate stream; use the same stable aggregate ID on both primary and standby. Group-level user-owned workflows (such as personal Nexora and Loopza case records) may have a null organisation scope when no tenant owns the record; those streams remain isolated by product, aggregate type and aggregate ID, and their event handlers must preserve the owning user boundary. The outbox service uses a nested Laravel transaction, so it participates in the caller's transaction. This is essential: a committed subscription/payment/order change must not exist without its corresponding durable event.

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

The Laravel application now has `App\Services\ClientChangeOutboxPublisher` and the Artisan command `php artisan nexora:outbox:publish`. A scheduler entry attempts publication every minute, prevents overlapping runs, and uses Laravel's `onOneServer()` election so only one scheduler node dispatches when all nodes share a compatible cache/lock store. Configure the same shared cache backend and prefix on every scheduler node; a local file/array cache on separate hosts does not coordinate them. Run the publisher only from the designated primary.

Configure both environment values on the primary and keep the secret out of source control:

- `NEXORA_REPLICATION_URL`: HTTPS base URL of the receiving backup service.
- `NEXORA_REPLICATION_SECRET`: long random shared secret, identical on sender and receiver.

The publisher now enforces per-aggregate FIFO dispatch: it will not send a later outbox row while an earlier row for the same organisation/product/aggregate remains unpublished. This intentionally allows a failed earlier event to hold later events for that aggregate rather than silently applying changes out of order. Different aggregates can continue independently. FIFO is based on outbox insertion order, so callers must create aggregate events in the same order as their increasing `aggregate_version`; this does not replace receiver-side version-gap checks or a materializing consumer. The outbox and inbox now also enforce a unique aggregate-version position per organisation/product/aggregate. The receiver rejects a second, different event that attempts to claim an already accepted version (HTTP 409). Tenant-scoped service-customer events must carry their organisation ID. Group-level user-owned workflows may legitimately use a null organisation scope when there is no tenant owner; do not invent or attach an unrelated organisation merely to populate this field.

The publisher sends events to `POST /api/replication/events` with an event UUID idempotency key, timestamp and HMAC-SHA256 signature over the exact request body. It marks an event published only after a successful 2xx response. Failed attempts remain pending, increment an attempt counter, record a safe error summary and wait at least five minutes before retrying.

**Operational prerequisite:** Laravel's scheduler must actually be invoked by the host (Linux cron or Windows Task Scheduler running `php artisan schedule:run` every minute). Merely deploying the code does not start the scheduler.

**Important limitation:** the signed receiver and durable inbox are implemented. A manually invoked backup processor now materializes only the `client.identity.upserted` / `client_identity` contract. It requires the referenced organisation and user to exist on the backup and is deliberately not scheduled automatically. Other product event types—including case, subscription, payment and billing events—are accepted into the inbox but have no materializer yet. A successful publisher response means the event was durably accepted by the inbox; it does not mean the destination business record has been applied. Keep the publisher disabled until the receiver is deployed and the operator has confirmed the backup's prerequisites. This is not automatic failover or a complete disaster-recovery guarantee.
## Backup receiver inbox

The Laravel API now exposes `POST /api/replication/events` and persists authenticated events in `client_change_inbox`. The receiver checks a five-minute timestamp window, verifies HMAC-SHA256 against the exact raw request bytes with constant-time comparison, validates product/event fields, checks the idempotency header, and stores a unique event UUID with a hash of the original body.

An exact replay receives an accepted duplicate response without creating a second row. Reusing an event UUID with a different body returns HTTP 409. The receiver acknowledges only after the inbox transaction commits.

The inbox processor is now implemented as an operator-invoked command, but it currently applies only the client identity upsert contract documented below. It enforces per-aggregate ordering, requires the referenced organisation and user to exist on the backup, rejects identity ownership conflicts and version gaps, and marks an event processed only after the destination write commits. It is not scheduled automatically. Do not treat inbox receipt as proof that subscriptions, cases, invoices, files, router state, or sessions are available on the backup.

The receiving inbox also enforces a unique non-null idempotency key in addition to the unique event UUID. A replay with the same event UUID and exact body is acknowledged as a duplicate; reusing an idempotency key for a different event UUID is rejected with HTTP 409. This prevents a sender retry or integration bug from creating two accepted inbox events for one business operation.


## Backup inbox materialization (first supported contract)

The backup now has an explicit processor command:

`php artisan nexora:inbox:process --limit=100`

The first supported event is `client.identity.upserted` with aggregate type
`client_identity`. Its payload must contain `external_reference`, a status
of `active`, `suspended`, or `closed`, and optional metadata. The aggregate
ID must equal the external reference. Events must include organisation and user
IDs that already exist on the backup; this processor does not silently create
accounts or infer identity mappings.

The materializer writes `client_service_identities` and records
`source_version`. The first event must be version 1 and each subsequent event
must be exactly the next version. Earlier pending events block later events
for the same aggregate. Unknown event types, missing tenant/user records,
conflicting identity ownership, and version gaps remain unprocessed and record
an error for operator review. This is intentionally fail-closed.

This is not yet a general product-data replication engine: subscription,
payment, billing, case, and other product events have no materializer until
their own validated handlers and transactional tests are added. The command
is not automatically scheduled yet; enable it on the designated backup only
after the backup's account/organisation identity prerequisites are replicated
and operational monitoring is configured.


## Read-only continuity diagnostics

Run `php artisan nexora:continuity:status` on the relevant application node to inspect pending and previously failed outbox/inbox events, the oldest pending timestamps, and unprocessed event types. This command is read-only: it does not publish, process, retry, or delete events. Use it as an operator check before enabling any backup-side processing schedule.