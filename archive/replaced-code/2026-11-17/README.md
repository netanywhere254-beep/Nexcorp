# Replaced code archive — 17 November 2026

This directory preserves exact pre-change source files that were superseded during the Nexora Group implementation work. These are historical snapshots; do not deploy or import them as current code.

## Archived snapshots

| Archived path | Original repository path | Reason |
|---|---|---|
| `backend-laravel/composer.json` | `backend/laravel/composer.json` | Preserves the prior dependency manifest, including the Laravel Tinker constraint that was removed to resolve the Laravel 13 dependency conflict. |
| `backup-node-postgres/api/package.json` | `backup/node-postgres/api/package.json` | Preserves the previous Express-based API package definition. |
| `backup-node-postgres/api/src/server.js` | `backup/node-postgres/api/src/server.js` | Preserves the Express server replaced by the NestJS TypeScript implementation. |
| `shared-foundation/backend/laravel/bootstrap/app.php` | `backend/laravel/bootstrap/app.php` | Preserves middleware registration before adding the production `organization.active` alias. |
| `shared-foundation/backend/laravel/routes/web.php` | `backend/laravel/routes/web.php` | Preserves routes before adding the protected organization context endpoint. |
| `shared-foundation/backend/laravel/tests/Feature/TenantAuthorizationTest.php` | `backend/laravel/tests/Feature/TenantAuthorizationTest.php` | Preserves tenant authorization tests before adding coverage for the real application endpoint. |

The earlier parent/subsidiary HTML pages and all other Git changes remain recoverable from Git history and the pull-request base commit. Only files explicitly copied into this folder are represented as standalone archive snapshots.

## Rules

1. Never delete or overwrite an archived snapshot to store a newer revision.
2. Create a new dated subfolder for each later replacement.
3. Record the original path, replacement path, date, reason, and verification result in `docs/CHANGE_REGISTER.md`.
4. Mark a replacement **UPDATED** only after the new file is committed and checked. Mark build/test validation separately; a committed file is not automatically a tested feature.
5. Keep secrets, credentials, private keys, and production data out of this archive.
