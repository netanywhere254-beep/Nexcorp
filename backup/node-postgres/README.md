# Standby architecture — Next.js + Node.js + PostgreSQL

This is the separate fallback technology foundation. It contains a Next.js web shell, an Express API with live/readiness endpoints, and a PostgreSQL connection. It is not the primary application and it does not synchronize records from Laravel/MySQL.

Run it using the backup Compose file documented in infra/README.md. Do not run it as a production replacement until data mapping, migration, authentication, permissions, integrations, backups and restore procedures have been tested.

The API exposes /health/live and /health/ready. The web screen can check the API's live endpoint. No business modules or customer data migration are implemented yet.
