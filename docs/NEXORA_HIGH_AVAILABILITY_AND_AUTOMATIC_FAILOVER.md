# Nexora high availability, automatic failover and client continuity

**Status:** Required target architecture; implementation and production readiness are not yet complete.
**Applies to:** Nexora Group platform, NexISP, Loopza and future group products.

## 1. Non-negotiable product requirement

A failure of a server, application process, database node, availability zone, network path or hosting environment must trigger automatic recovery to a healthy standby wherever technically safe. Customers must not be asked to register again, rebuild profiles, recreate subscriptions or manually move their records. A single authoritative client identity and synchronised client/service data model must follow the customer across failover and planned migrations.

The objective is no customer-visible interruption where possible, with a measured and minimised interruption when a failure makes a brief reconnect unavoidable. Do not promise literal zero downtime until the complete production topology has been deployed, load-tested and failure-tested.

## 2. Target topology

- Run the primary and standby application capacity in separate failure domains (preferably separate availability zones; use a second region for disaster recovery).
- Put a managed health-aware load balancer/reverse proxy in front of stateless application instances. It must stop sending new traffic to unhealthy instances and route to healthy capacity automatically.
- Keep application instances replaceable and stateless. Store sessions, queues, uploaded files, cache state and secrets in shared/replicated services, not on one server's local disk.
- Use the same supported relational database engine and compatible schema for primary and standby database nodes. The current Laravel + MySQL primary should have MySQL-compatible replication/managed HA. Do not treat the existing PostgreSQL standby shell as a drop-in database replica: MySQL and PostgreSQL are not wire-compatible or automatically synchronised.
- Use a database topology with a single writable primary and synchronously replicated standby within the low-latency HA failure domain where supported. Use asynchronous cross-region replication for disaster recovery where synchronous replication would impose unacceptable latency.
- Health monitoring must cover user-facing synthetic transactions, application readiness, database read/write readiness, queue processing, storage, DNS, TLS certificates, network paths and critical integrations.

## 3. Automatic failover control

1. Continuously monitor health from more than one independent probe/location.
2. Distinguish a failed instance from a shared dependency failure; do not blindly fail over all application nodes when the database or network is the common fault.
3. Promote a standby only after quorum/lease or equivalent fencing proves the old writer cannot continue accepting writes. This prevents split-brain and divergent customer records.
4. Update the database writer endpoint/service discovery and route application traffic to healthy capacity automatically.
5. Reconnect clients using stable service endpoints. Keep DNS TTL short enough for the selected design, but prefer load-balancer or managed failover endpoints over DNS-only failover.
6. Verify the new writer, schema version, queue consumers, storage and payment/webhook processing before declaring recovery complete.
7. Alert operators, create an incident record and preserve a complete failover audit trail.
8. Automatically fail back only after the original environment is healthy, caught up, fenced and validated. Otherwise keep the recovered environment active until an approved controlled failback.

A recovery controller must have independent monitoring and access to the failover control plane; it must not depend solely on the application node it is meant to recover.

## 4. One common client record and continuity model

Maintain a canonical, globally unique customer/client ID that never changes when infrastructure or hosting providers change. NexISP and Loopza may keep product-specific profiles and permissions, but must reference the same canonical identity where the customer has a group account and has consented to the linkage.

Replicate and reconcile, with tenant and country boundaries intact:

- customer identity, verified contact methods and organisation membership;
- subscriptions, packages, service entitlements and lifecycle state;
- router/device identifiers, provisioning state and configuration versions (NexISP);
- invoices, payment references, payment status and immutable transaction/audit history;
- Loopza campaigns, assets, schedules, account connections and workflow state;
- support tickets, case references, permissions, consent records and security events;
- file/object metadata and the actual encrypted objects.

Use versioned change events/outbox records, idempotency keys and replay-safe consumers. Every write and external callback must be safe to retry. Keep payment provider transaction IDs and webhook event IDs unique to prevent duplicate charges or duplicate fulfilment. Preserve the independent Nexora and Loopza case-number sequences during replication and migration; never allocate the same sequence independently on two writable sites.

The common client list is a shared logical source of truth, not two independently writable spreadsheets or databases. In a failover, the promoted database becomes the only writer. Do not allow split-brain dual writes unless a deliberately engineered conflict-free data model is introduced and tested.

## 5. Sessions, devices and client reconnection

- Use shared/replicated session storage and stable authentication signing/encryption keys with controlled rotation.
- Preserve customer IDs, usernames, verified contacts, device IDs and service references across a cutover.
- Use stable API and portal hostnames; keep mobile/web clients configured to those hostnames rather than server IP addresses.
- Support short-lived access tokens with refresh/re-authentication that does not require account recreation. Revoke compromised credentials consistently across regions.
- For ISP services, keep router provisioning and subscriber authentication endpoints highly available. Provide redundant RADIUS/DNS/DHCP/control-plane capacity where deployed; preserve the same subscriber credentials and service profile. A router should reconnect to a secondary endpoint without a technician manually migrating the customer.
- Buffer retryable events during short outages and replay them in order where ordering matters. Show a clear degraded-service state rather than losing submissions.

## 6. Files, queues, integrations and external services

- Store uploads and generated assets in encrypted object storage with versioning and cross-zone/region replication. Keep checksums and metadata; verify objects after restore.
- Use durable queues with replicated brokers or managed durable queue services. Make jobs idempotent and support dead-letter/replay procedures.
- Payment, SMS/OTP, email, social-platform and router-management integrations need documented provider failover, retry, timeout and circuit-breaker behaviour. An external provider outage cannot be solved by moving the application server; isolate it and queue safe work.
- Do not send a second payment request simply because the first response timed out. Query/reconcile by the original idempotency key/provider reference.
- Keep secrets in a managed secret store or equivalent protected facility; standby capacity receives only the minimum authorised secrets.

## 7. Targets, backups and recovery testing

Initial engineering targets to validate through tests and provider capabilities:

| Measure | Initial target |
|---|---|
| In-zone application instance failover | automatic, typically seconds |
| HA database failover | target under 1 minute, subject to engine/provider |
| Cross-region disaster recovery RTO | target under 15 minutes |
| Cross-region disaster recovery RPO | target under 1 minute for replicated critical records |
| Backup retention | policy-based, encrypted, immutable/offline copy |
| Restore validation | automated checks plus scheduled full restore drills |

These are design objectives, not current service guarantees. Final SLOs must be approved after realistic load, latency, provider, cost and recovery testing. Backups are not a substitute for replication; replication is not a substitute for immutable backups.

Run automated fault-injection tests for application process loss, host loss, zone loss, database primary loss, replication lag, queue outage, storage outage, DNS/TLS failure and integration timeouts. Run scheduled restore drills and measure actual RTO/RPO. Verify customer records, balances, subscriptions, case histories, audit trails and files after every drill.

## 8. Planned migration with no customer re-registration

Use a controlled blue/green or rolling migration:

1. Provision and secure the destination environment and bring its schema up to a compatible version.
2. Establish continuous replication/change-data capture from the authoritative source and verify lag/checksums.
3. Run the destination in shadow/read-only mode and compare representative reads and business calculations.
4. Validate authentication, tenant/country permissions, billing, payment reconciliation, router provisioning, integrations, file access and audit trails.
5. At cutover, briefly fence writes or use a carefully tested dual-write/outbox barrier; drain in-flight jobs and capture the final change position.
6. Promote one destination writer, switch the stable traffic endpoint and resume idempotent queues.
7. Monitor synthetic customer journeys and reconcile all writes. Keep the source intact and fenced for a defined rollback window.
8. Roll back only through a controlled reverse-replication/reconciliation plan. Never simply reopen two writable databases.

The customer ID, login, verified contact, subscription/service ID, invoices, payment history and product data remain the same. Migration must be transparent to customers except for a possible brief reconnection or re-authentication when technically unavoidable.

## 9. Security and governance

- Preserve tenant, country, role, consent and data-residency boundaries in replicas and backups.
- Encrypt traffic and data at rest; restrict replication credentials and failover control-plane permissions.
- Require MFA/strong authentication for manual override, promotion override and failback.
- Record who/what triggered failover, health evidence, replication position, promoted node, traffic changes and reconciliation results.
- IT Manager technical operations may monitor system health and execute authorised infrastructure procedures, but must not gain executive, finance, governance or restricted Loopza case-content privileges.
- Test that recovery does not bypass staff OTP approval, case reviewer restrictions, audit logging or country-scoped access grants.

## 10. Current repository limitation

The repository contains a Laravel/MySQL primary foundation and a separate Next.js/NestJS/PostgreSQL standby code foundation. The latter is **not** a live replica, has no shared client database, no cross-stack synchronisation and no automatic production failover. A new MySQL 8.4 primary/replica Compose reference and GTID/binlog configuration have been added for isolated lab work only; replication bootstrap, separate failure-domain deployment, quorum/fencing, promotion and traffic switching are still not implemented. Do not route production customers to it or describe the current repository as zero-downtime/high-availability until the compatible database replication, feature parity, traffic control, monitoring, security and recovery tests described here are implemented.

The next implementation stages are: (a) decide and provision a production-compatible MySQL HA topology; (b) extract shared client identity and product service records into explicit versioned models/events; (c) implement health-aware traffic and database failover orchestration; (d) replicate files, queues and integration state; (e) implement blue/green migration tooling; (f) add automated failover, reconciliation and restore tests.
