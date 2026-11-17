# Nexora Group ecosystem — Nexora, NexISP + Loopza

This repository contains the shared platform foundation for Nexora Group and its products: Nexora, NexISP, and Loopza.

## Architecture choice

- **Primary:** Laravel modular monolith + MySQL, located in backend/laravel.
- **Standby alternative:** Next.js web + NestJS API + PostgreSQL, located in backup/node-postgres.

The primary is the source of truth for new features and business records. The standby is kept separate as an alternative technology foundation. It is not automatically synchronized and is not yet a production failover system.

Read docs/ARCHITECTURE_DECISION.md before starting either stack.

## Start the primary application

Run from the repository root.

Windows PowerShell:

    Copy-Item infra/.env.primary.example infra/.env.primary
    notepad infra/.env.primary

macOS/Linux:

    cp infra/.env.primary.example infra/.env.primary

Edit the environment file and replace both placeholder MySQL passwords with different long, unique hexadecimal secrets. You can generate a secret with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Generate a separate value for each password. For a persistent environment, generate an APP_KEY with `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` and paste the complete result into APP_KEY. Then run:

    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml config
    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml up -d --build
    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml exec app php artisan migrate --force

Open http://localhost:8080. Health endpoint: http://localhost:8080/health.

For a persistent deployment, set a stable APP_KEY in the environment file. Leaving it blank causes the container entrypoint to generate a temporary key when the container starts; that is acceptable only for a disposable local test.

## Start the standby alternative

Use a separate terminal and separate environment file.

Windows PowerShell:

    Copy-Item infra/.env.backup.example infra/.env.backup
    notepad infra/.env.backup

macOS/Linux:

    cp infra/.env.backup.example infra/.env.backup

Replace the PostgreSQL password, then run:

    docker compose --env-file infra/.env.backup -f infra/docker-compose.backup.yml config
    docker compose --env-file infra/.env.backup -f infra/docker-compose.backup.yml up -d --build

Open http://localhost:3100. API health: http://localhost:4100/health/live. Database readiness: http://localhost:4100/health/ready.

## Stop without deleting database data

Primary:

    docker compose --env-file infra/.env.primary -f infra/docker-compose.primary.yml down

Standby:

    docker compose --env-file infra/.env.backup -f infra/docker-compose.backup.yml down

Do not add -v unless you intentionally want to delete that stack's database volume.

## What is implemented in this foundation

- Laravel 13 application shell and Apache/PHP Docker image.
- MySQL 8.4 primary Compose service with a persistent volume and health check.
- Initial users, organizations, organization-membership, and audit-log migration/models.
- Primary /health endpoint and automated feature test.
- Separate NestJS API with PostgreSQL connectivity and live/readiness endpoints.
- Separate Next.js standby screen with API health-check interaction.
- Separate Compose files, ports, networks, volumes, and example environment files.
- CI checks for both code paths and Docker build configurations.

## What is not complete yet

This is a bootable foundation, not the finished commercial products. Login and registration flows, production-grade authorization and tenant isolation, NexISP customers/plans/subscriptions/invoices/payments, M-Pesa callbacks and reconciliation, MikroTik provisioning, Loopza campaign workflows, advertising-provider permissions, admin portals, security hardening, production deployment, monitoring, backup/restore, and cross-database migration remain future implementation work.

Never treat a saved schema, Docker configuration, or UI component as a working business feature until it has been implemented and tested end to end.
