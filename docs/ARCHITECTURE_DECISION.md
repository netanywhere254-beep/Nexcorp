# Architecture decision: primary application and standby alternative

**Decision:** retain both technology paths in the repository, with one authoritative primary and one isolated standby foundation.

## 1. Primary — Laravel + MySQL

The primary application is the Laravel modular monolith in backend/laravel, with MySQL as its database. NexISP and Loopza are planned as separately authorized modules inside this application, sharing the platform foundation where appropriate.

Primary Compose file: infra/docker-compose.primary.yml  
Environment template: infra/.env.primary.example  
Local web address: http://localhost:8080  
Health endpoint: http://localhost:8080/health  
MySQL host port: 3307

This is the canonical path for new application features and the source of truth for live business records.

## 2. Standby — Next.js + Node.js + PostgreSQL

The fallback technology path is maintained separately under backup/node-postgres:

- web/ — Next.js web application.
- api/ — NestJS API.
- PostgreSQL — separate database managed by the backup Compose stack.

Backup Compose file: infra/docker-compose.backup.yml  
Environment template: infra/.env.backup.example  
Web address: http://localhost:3100  
API live health: http://localhost:4100/health/live  
API database readiness: http://localhost:4100/health/ready  
PostgreSQL host port: 5433

This is an independently buildable technical foundation. It is not a second live copy of the Nexora business application and it does not yet contain feature parity with the primary Laravel application.

## 3. Isolation rules

- Keep the primary and backup source trees, environment files, Docker networks, and database volumes separate.
- Do not point both applications at the same database.
- Do not copy production credentials between the stacks.
- The primary MySQL data volume and backup PostgreSQL data volume are distinct.
- The two stacks may be run side by side for development because their default host ports are different. Running both in production is not required.
- New business features are implemented in the primary application first. The standby implementation is updated only when a deliberate recovery or migration requirement calls for it.

## 4. What “backup” means at this stage

The standby stack is a fallback code and infrastructure option. It is not automatic failover, continuous replication, or a disaster-recovery guarantee.

MySQL and PostgreSQL are different database engines. Switching over safely requires, at minimum:

1. A documented mapping between the primary schema and the backup schema.
2. Repeatable export, transformation, and import scripts.
3. A tested process for freezing writes and capturing a final consistent backup.
4. Reconciliation of customers, subscriptions, invoices, payments, campaigns, files, and audit history.
5. Equivalent authentication, authorization, tenant isolation, secrets, integrations, and operational monitoring.
6. A rehearsed restore and cutover test with measured recovery time and recovery point.

Until those steps and feature parity are implemented and tested, do not use the backup stack as the live replacement for production records.

## 5. Local setup

Run commands from the repository root.

### Primary

Windows PowerShell:

    Copy-Item infra/.env.primary.example infra/.env.primary
    notepad infra/.env.primary
    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml config
    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml up -d --build
    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml exec app php artisan migrate --force

macOS/Linux:

    cp infra/.env.primary.example infra/.env.primary
    # Edit infra/.env.primary and replace both placeholder passwords.
    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml config
    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml up -d --build
    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml exec app php artisan migrate --force

Open http://localhost:8080 and http://localhost:8080/health.

### Standby alternative

Windows PowerShell:

    Copy-Item infra/.env.backup.example infra/.env.backup
    notepad infra/.env.backup
    docker compose --env-file infra/.env.backup -f infra/docker-compose.backup.yml config
    docker compose --env-file infra/.env.backup -f infra/docker-compose.backup.yml up -d --build

macOS/Linux:

    cp infra/.env.backup.example infra/.env.backup
    # Edit infra/.env.backup and replace the placeholder password.
    docker compose --env-file infra/.env.backup -f infra/docker-compose.backup.yml config
    docker compose --env-file infra/.env.backup -f infra/docker-compose.backup.yml up -d --build

Open http://localhost:3100. Check the API at http://localhost:4100/health/live and its database readiness at http://localhost:4100/health/ready.

## 6. Stopping services and preserving data

Stop the primary stack:

    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml down

Stop the backup stack:

    docker compose --env-file infra/.env.backup -f infra/docker-compose.backup.yml down

Do not add -v unless you deliberately intend to delete the corresponding database volume. Docker volumes are persistence, not backups.

## 7. Delivery status and limitations

The repository now contains the initial Laravel application shell, primary MySQL Compose definition, initial shared platform migration, a basic health test, and a separate Next.js/NestJS/PostgreSQL standby shell with a live-health test.

This does not mean the full Nexora, NexISP, or Loopza products are complete. Authentication screens, roles and permissions, tenant-enforcement policies, billing, M-Pesa, router provisioning, campaign workflows, provider integrations, production TLS, monitoring, backups, and cross-database migration tooling remain to be implemented and tested.

The GitHub Actions pipeline is expected to validate the source, tests, and Docker builds. A green CI run is evidence that these foundations build; it is not a production deployment or a disaster-recovery certification.


## 8. Required future: automatic high availability and client continuity

The separate PostgreSQL standby described above is not the final disaster-recovery design. Nexora requires automatic health-based failover and a common, continuously synchronised client/service record across NexISP, Loopza and group services. The target design, including fencing against split-brain, compatible MySQL HA, stable client IDs, replicated sessions/files/queues, automatic routing, blue/green migration and tested recovery objectives, is defined in `docs/NEXORA_HIGH_AVAILABILITY_AND_AUTOMATIC_FAILOVER.md`.

Do not represent the current standby stack as a live failover environment. It is not synchronised with the Laravel/MySQL primary and must not receive production traffic as a replacement until compatible data replication, feature parity, security controls, automated promotion, reconciliation and disaster-recovery drills are implemented and verified. Customer records must never be split between independently writable client databases.

## 9. Failover implementation decision — keep the application and database compatible

For the first production high-availability implementation, the standby that takes over Nexora business traffic must run the **same Laravel application release and compatible MySQL schema** as the primary. Do not use the current Next.js/NestJS/PostgreSQL starter as the automatic failover target.

This is the selected implementation path because it avoids a risky live conversion of customer, billing, subscription, payment and restricted case data between MySQL and PostgreSQL during an outage.

The target topology is:

1. Two or more stateless Laravel application nodes running the same tested release behind a health-aware traffic endpoint.
2. One authoritative MySQL writer with a supported HA replica/cluster and a documented, tested promotion and fencing mechanism.
3. Shared or replicated sessions, cache, queues, encrypted object storage and secrets.
4. Stable public hostnames and API endpoints so customer devices reconnect without re-registration.
5. A separate recovery controller/monitor that can detect failure and coordinate safe promotion without depending on the failed application node.
6. A tested restore and failback process, with immutable backups in addition to replication.

The MySQL 8.4 Compose file in this repository remains a **local lab reference only**. It must not be promoted to production merely because its containers start. Before production use, implement and test replication bootstrap, replica health/lag monitoring, quorum or fencing, promotion, writer endpoint switching, failback, credential isolation and full restore drills.

The Next.js/NestJS/PostgreSQL code remains available as an optional future product/API path. It may be used for a deliberate migration only after feature parity, schema mapping, data reconciliation, security review and a controlled cutover have been completed. It is not the disaster-recovery writer for the Laravel/MySQL application.

**Customer continuity rule:** there must be exactly one authoritative writer for each business dataset at a time. Stable customer IDs and product service references survive node replacement and hosting migration. Never solve an outage by allowing two independent databases to accept writes and attempting an unverified merge later.

