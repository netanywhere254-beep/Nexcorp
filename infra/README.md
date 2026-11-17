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
