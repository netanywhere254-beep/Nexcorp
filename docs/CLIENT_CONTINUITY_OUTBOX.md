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