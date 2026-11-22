# MySQL 8.4 primary/replica reference

**Status: configuration foundation only.** This adds compatible MySQL 8.4 nodes and GTID/binlog settings. It does not initialise replication, provision a replica snapshot, monitor replication lag, promote a standby, fence a former primary, or route application traffic. It is not a production failover solution.

## Why this replaces the PostgreSQL standby for database continuity

Laravel uses MySQL. PostgreSQL cannot be a native MySQL replica. The separate PostgreSQL/NestJS stack remains an alternative application reference only; it must not receive production traffic as if it contained the same customer records.

## Start the isolated reference lab

From the repository root, create a private local environment file at infra/.env.mysql-ha (do not commit it) with strong unique values:

    MYSQL_ROOT_PASSWORD=replace-with-a-long-random-secret
    MYSQL_DATABASE=nexora
    MYSQL_USER=nexora_app
    MYSQL_PASSWORD=replace-with-another-long-random-secret
    MYSQL_REPLICA_ROOT_PASSWORD=replace-with-a-third-long-random-secret
    MYSQL_PRIMARY_PORT=3307
    MYSQL_REPLICA_PORT=3308

Run:

    docker compose --env-file infra/.env.mysql-ha -f infra/docker-compose.mysql-ha-reference.yml config
    docker compose --env-file infra/.env.mysql-ha -f infra/docker-compose.mysql-ha-reference.yml up -d

This is an isolated local lab. Both database ports bind to loopback only and the internal network has no external connectivity. It does not connect to the existing primary stack or copy its data.

## Required replication bootstrap (not automated here)

Before enabling replication in a real environment:

1. Provision primary and replica in separate failure domains with private network connectivity, TLS, firewall restrictions, encrypted disks and monitored storage.
2. Create a dedicated replication account on the primary with only the required replication privilege; use a secret manager and TLS.
3. Take a consistent physical backup or transactionally consistent logical snapshot with GTID/binlog coordinates. Verify the backup and restore it to the empty replica.
4. Configure the replica to use the primary private endpoint and SOURCE_AUTO_POSITION=1; start replication.
5. Confirm Replica_IO_Running=Yes, Replica_SQL_Running=Yes, no SQL/IO errors, GTID execution progress, and acceptable measured lag.
6. Test recovery from a fresh snapshot and prove the replica catches up after interruptions.

Do not start replication against an empty database and assume it will copy the primary's existing tables. A correct initial snapshot is mandatory.

## Promotion safety requirements

A read-only replica must not be promoted merely because a ping fails. Promotion requires independent quorum/lease evidence and fencing that prevents the old primary from accepting writes. Then verify GTID catch-up and data consistency, promote exactly one writer, update the stable database endpoint, and only then resume write traffic. A former primary must be fenced and rebuilt/rejoined as a replica before it can re-enter service.

This repository does not yet include the quorum/fencing controller, automatic promotion, database endpoint switching, or failback automation. Never manually make both nodes writable. Do not point Laravel at the replica until it has been safely promoted.

## Application continuity still required

MySQL replication alone does not synchronise local sessions, uploaded files, queue state, cache, secrets or external payment/SMS integrations. Those require shared/replicated services and idempotent processing. The existing outbox/inbox is an event transport foundation, not a substitute for complete database replication.

## Production recommendation

For production, prefer a managed MySQL-compatible HA service with documented automatic failover and a stable writer endpoint, or deploy a properly engineered MySQL InnoDB Cluster/Group Replication topology with quorum, fencing, Router and tested recovery procedures. Choose after comparing provider availability, regional latency, cost and operational skills. Do not claim zero downtime until customer journeys and recovery objectives have been tested under real failure conditions.
