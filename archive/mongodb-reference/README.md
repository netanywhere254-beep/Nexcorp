# MongoDB reference archive — optional third technology path

**Status: ARCHIVED / REFERENCE ONLY / NOT PRODUCTION / NOT A DATA BACKUP**

This folder preserves MongoDB as an optional future technology path and keeps the Mongoose examples separate from the active application code.

## Current architecture remains unchanged

1. **Primary:** Laravel 13 + MySQL 8.4.
2. **Standby application alternative:** Next.js + NestJS + PostgreSQL 16.
3. **Optional reference only:** MongoDB 8.0, isolated for evaluation and future experiments.

The MongoDB service is not part of the primary or standby Compose stacks. It does not receive application traffic, replicate records, or participate in automatic failover.

## Important: MongoDB is not yet a second or third data backup

Running a third database does not create a backup of MySQL or PostgreSQL. Their schemas, transactions, indexes, constraints, and data types differ. No synchronization, migration, replication, restore, or failover process is implemented here.

For real disaster recovery, the project still needs:
- Automated, encrypted MySQL and PostgreSQL backups.
- Off-site and preferably immutable backup copies.
- Documented retention and access controls.
- Scheduled restore drills with measured recovery time and recovery point.
- A reviewed migration/ETL process if data is ever copied into MongoDB.
- Consistency checks and a tested cutover procedure before MongoDB could be considered a recovery target.

Until those controls exist, treat this as a preserved development/reference option only.

## Start the isolated MongoDB reference service

From the repository root, create a private environment file:

**Windows PowerShell**
```powershell
Copy-Item infra/.env.mongodb.example infra/.env.mongodb
notepad infra/.env.mongodb
```

**macOS/Linux**
```bash
cp infra/.env.mongodb.example infra/.env.mongodb
```

Replace the example password with a long, unique secret. Do not commit `infra/.env.mongodb`.

Validate and start only this optional service:

```bash
docker compose --env-file infra/.env.mongodb -f infra/docker-compose.mongodb-reference.yml config
docker compose --env-file infra/.env.mongodb -f infra/docker-compose.mongodb-reference.yml up -d
```

The database port is bound to localhost (default host port 27018). It is not exposed directly to the public internet. No application is connected to it.

Stop it without deleting its data:

```bash
docker compose --env-file infra/.env.mongodb -f infra/docker-compose.mongodb-reference.yml down
```

Do not add `-v` unless you intentionally want to delete the MongoDB reference volume.

## Archived Mongoose examples

See [SUBMITTED_SNIPPETS.md](SUBMITTED_SNIPPETS.md) for selected MongoDB/Mongoose code supplied during the project discussion and the review notes explaining how it could be adapted later.

## Promotion requirements

MongoDB must not be promoted to a real third production stack until the owner explicitly approves a use case, data ownership, schema, security model, tenant isolation, backup/restore policy, monitoring, cost, and operational support. A separate MongoDB service should only be introduced if it solves a concrete problem that MySQL and PostgreSQL do not solve more simply.
