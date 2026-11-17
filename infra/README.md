# NexISP infrastructure references

## NestJS + PostgreSQL + Redis reference stack

Files:
- `docker-compose.nestjs-reference.yml` — reference Compose stack.
- `.env.nexisp.example` — safe configuration template; contains placeholders only.
- `.gitignore` — excludes the real local environment file.

**Architecture warning:** the Nexcorp repository's current application direction is Laravel + MySQL. This Compose file belongs to the optional NestJS + PostgreSQL + Redis integration architecture described by the Prisma and NestJS reference files. It does not replace the active Laravel/MySQL design and should not be deployed until the team explicitly approves that architecture and supplies a compatible NestJS application.

The Compose file expects a NestJS API project and a `backend/Dockerfile`. The current backend folder may not contain those yet, so the stack is a configuration reference and is not guaranteed to start today.

## Configure and run (after the NestJS backend exists)

Run these commands from the repository root.

### Windows PowerShell

```powershell
Copy-Item infra/.env.nexisp.example infra/.env.nexisp
notepad infra/.env.nexisp
```

### macOS / Linux

```bash
cp infra/.env.nexisp.example infra/.env.nexisp
nano infra/.env.nexisp
```

Edit the file and replace all placeholder secrets. Generate two different secrets, for example with `openssl rand -hex 32`. Keep the PostgreSQL password consistent in `POSTGRES_PASSWORD` and `DATABASE_URL`; keep the Redis password consistent in `REDIS_PASSWORD` and `REDIS_URL`. Use URL-safe characters only (the suggested hex output is URL-safe).

Validate the Compose configuration:

```bash
docker compose --env-file infra/.env.nexisp -f infra/docker-compose.nestjs-reference.yml config
```

Build and start:

```bash
docker compose --env-file infra/.env.nexisp -f infra/docker-compose.nestjs-reference.yml up -d --build
```

View service status and logs:

```bash
docker compose --env-file infra/.env.nexisp -f infra/docker-compose.nestjs-reference.yml ps
docker compose --env-file infra/.env.nexisp -f infra/docker-compose.nestjs-reference.yml logs -f api
```

Stop containers without deleting stored database/cache volumes:

```bash
docker compose --env-file infra/.env.nexisp -f infra/docker-compose.nestjs-reference.yml down
```

**Data-loss warning:** adding `-v` to `down` deletes the named data volumes. Do not do that unless you intentionally want to erase the database and Redis data and have verified backups.

## Security and operations notes

- PostgreSQL and Redis are not published to host ports; only the API is bound to localhost by default.
- Put a maintained TLS reverse proxy in front of the API before remote/public access. Do not expose the API directly without authentication, rate limits, TLS, and firewall controls.
- Use unique secrets for each environment. Never commit the real `.env.nexisp` file or paste production secrets into chat or source control.
- Back up PostgreSQL regularly and test restore procedures. A named Docker volume is persistence, not a backup.
- Pin and update image versions deliberately; test upgrades and database migrations before production.
- Configure application health checks, structured redacted logs, monitoring, resource limits, and a tested disaster-recovery process before production.
- Redis is configured with authentication and persistence, but Redis data should not be treated as the authoritative financial or subscription record.
- The API container needs a real Dockerfile and a working NestJS build. Its database migrations must be run through a controlled deployment process, not blindly on every container start.


## Prepare a Debian/Ubuntu server

The repository includes [install-nexisp.sh](install-nexisp.sh), which prepares a Debian/Ubuntu host by installing Docker Engine, Git, and Docker Compose (v2 when available), then enables and starts Docker.

From the repository root on the Linux server:

```bash
bash infra/install-nexisp.sh
```

The script checks the Linux distribution, uses sudo when needed, verifies the Docker and Compose commands, and prints the next steps. It intentionally does not run a full `apt-get upgrade -y`; schedule operating-system upgrades separately because they can restart services or change a production server.

The script does **not** deploy NexISP. The optional NestJS Compose stack remains a reference configuration and requires a compatible NestJS application and `backend/Dockerfile` before it can be built. Review the architecture warning above before proceeding.

**Docker privilege warning:** users added to the `docker` group effectively receive root-level control of the host. The installer does not add users automatically. Only a trusted server administrator should run the optional `usermod -aG docker USERNAME` command printed by the installer, then sign out and back in.

## Optional Nexora + NexISP + Loopza multi-service reference

The repository also contains an alternative three-API / three-database Compose design:

- [docker-compose.nexora-multiservice-reference.yml](docker-compose.nexora-multiservice-reference.yml)
- [.env.nexora-stack.example](.env.nexora-stack.example)

It defines separate API containers and isolated PostgreSQL databases for Nexora, NexISP, and Loopza. Each database has its own named volume, credentials, health check, and private Docker network. API host ports default to localhost only: Nexora 3101, NexISP 3102, and Loopza 3103.

The connection variables intentionally match the Prisma reference schemas:
- Nexora receives `NEXORA_DATABASE_URL`.
- NexISP's current Prisma reference expects `DATABASE_URL`; Compose maps the host-side `NEXISP_DATABASE_URL` value into that container variable.
- Loopza receives `LOOPZA_DATABASE_URL`.

**Architecture warning:** this is an optional Node.js/Prisma + PostgreSQL microservice proposal. The current project blueprint and database design still specify a Laravel modular monolith + MySQL. Do not treat this Compose file as approval to change the architecture or as a production deployment.

**Build limitation:** the Compose build contexts are `../nexora`, `../nexisp`, and `../loopza` relative to this file. Those application directories and their Dockerfiles are not yet present in the repository. The stack cannot build until each service has a compatible application, Dockerfile, health/startup behavior, and reviewed database migrations.

### Configure and validate

Run from the repository root.

Windows PowerShell:

```powershell
Copy-Item infra/.env.nexora-stack.example infra/.env.nexora-stack
notepad infra/.env.nexora-stack
```

macOS / Linux:

```bash
cp infra/.env.nexora-stack.example infra/.env.nexora-stack
nano infra/.env.nexora-stack
```

Replace every placeholder. Use a different long, URL-safe password for each database and keep each password identical in its corresponding `POSTGRES_PASSWORD` and database URL. Do not commit or share the real environment file.

Validate the Compose model before building:

```bash
docker compose --env-file infra/.env.nexora-stack -f infra/docker-compose.nexora-multiservice-reference.yml config
```

After the three service folders and Dockerfiles exist and the architecture is approved:

```bash
docker compose --env-file infra/.env.nexora-stack -f infra/docker-compose.nexora-multiservice-reference.yml up -d --build
docker compose --env-file infra/.env.nexora-stack -f infra/docker-compose.nexora-multiservice-reference.yml ps
docker compose --env-file infra/.env.nexora-stack -f infra/docker-compose.nexora-multiservice-reference.yml logs -f nexora_api
```

Use `nexisp_api` or `loopza_api` instead of `nexora_api` to view the other API logs. Stop the stack without deleting database volumes:

```bash
docker compose --env-file infra/.env.nexora-stack -f infra/docker-compose.nexora-multiservice-reference.yml down
```

Do not add `-v` unless you deliberately intend to delete all three databases and have verified backups. Docker volumes are persistence, not backups.

Before any public deployment, add a TLS reverse proxy, authentication, rate limiting, firewall controls, backups with tested restores, monitoring, controlled migrations, and API health checks. The three isolated networks also mean cross-product reporting must use authenticated service APIs or a separately designed integration layer; the services cannot directly query one another's databases.

## Continuous integration checks

The repository's GitHub Actions workflow is [../.github/workflows/deploy.yml](../.github/workflows/deploy.yml). It runs on pushes and pull requests targeting `main`, and can also be started manually from the Actions tab.

Current checks:
- Validates the Nexora, NexISP, and Loopza Prisma reference schemas using a pinned Prisma CLI version and placeholder CI database URLs. Schema validation does not connect to or migrate a database.
- Validates the optional three-service Docker Compose configuration using the committed example environment file.
- Checks the Debian/Ubuntu installer script with `bash -n`.

**This is validation CI, not a production deployment.** The current repository does not yet have root or per-service Node package manifests/lockfiles, actual migration directories, a working test suite, or the three application Dockerfiles. Therefore the workflow intentionally does not run `npm ci`, `npm test`, `prisma migrate deploy`, or `docker build`; those commands would fail or misleadingly imply that the applications are implemented. Add application-specific build and test jobs when the relevant application source and lockfiles are committed. Add a protected deployment job only after a deployment target, environment secrets, approvals, health checks, and rollback procedure are configured.

## Optional single-backend + frontend + PostgreSQL reference

A separate Compose proposal based on the supplied single-backend stack is stored in:

- [docker-compose.master-ecosystem-reference.yml](docker-compose.master-ecosystem-reference.yml)
- [.env.master-ecosystem.example](.env.master-ecosystem.example)

It describes one backend container, one Next.js frontend container, and one PostgreSQL 15 database. The real local environment file must be created from the example:

Windows PowerShell:

```powershell
Copy-Item infra/.env.master-ecosystem.example infra/.env.master-ecosystem
notepad infra/.env.master-ecosystem
```

macOS / Linux:

```bash
cp infra/.env.master-ecosystem.example infra/.env.master-ecosystem
nano infra/.env.master-ecosystem
```

Replace the placeholder password with a long, unique, URL-safe secret. Keep it consistent in `POSTGRES_PASSWORD` and `DATABASE_URL`. Never commit the real environment file.

Validate the Compose configuration:

```bash
docker compose --env-file infra/.env.master-ecosystem -f infra/docker-compose.master-ecosystem-reference.yml config
```

Only after the compatible root `Dockerfile`, `Dockerfile.frontend`, backend application, and Next.js application exist:

```bash
docker compose --env-file infra/.env.master-ecosystem -f infra/docker-compose.master-ecosystem-reference.yml up -d --build
docker compose --env-file infra/.env.master-ecosystem -f infra/docker-compose.master-ecosystem-reference.yml ps
docker compose --env-file infra/.env.master-ecosystem -f infra/docker-compose.master-ecosystem-reference.yml logs -f backend
```

Stop without deleting persistent database data:

```bash
docker compose --env-file infra/.env.master-ecosystem -f infra/docker-compose.master-ecosystem-reference.yml down
```

Do not add `-v` unless you intend to delete the database volume and have verified backups.

**Architecture and build warning:** this is a Node/Next.js + PostgreSQL proposal, not the active Laravel modular monolith + MySQL direction. The repository currently has no compatible root backend Dockerfile or `Dockerfile.frontend`, and no complete backend/Next.js application setup. It is not currently buildable or deployable. The frontend API URL is passed as a build argument because Next.js commonly embeds `NEXT_PUBLIC_*` values during build; `http://localhost:4000` is for local browser access only. Use a public HTTPS URL or same-origin reverse-proxy route for a real deployment. This stack and the three-database reference both default to host port 5432; do not run both together without changing one host port.

