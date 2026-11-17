# Nexora Group — Change and Replacement Register

Last updated: 2026-11-17  
Working branch: `implementation/nexora-group-audit`  
Pull request: [#1](https://github.com/netanywhere254-beep/Nexcorp/pull/1)

This register distinguishes a committed source update from a verified build or working production feature.

## Replacement register

| ID | Previous file / implementation | Current replacement | Status | Verification |
|---|---|---|---|---|
| CHG-001 | `backend/laravel/composer.json` with Laravel Tinker dependency constraint | Updated Laravel dependency manifest on this branch | VERIFIED — committed in PR #1 | Composer dependency installation, SQLite migrations and PHPUnit passed in CI run #113. |
| CHG-002 | Express API: `backup/node-postgres/api/src/server.js` | NestJS TypeScript API under `backup/node-postgres/api/src/` | VERIFIED — committed in PR #1 | NestJS build and tests passed in CI run #113. |
| CHG-003 | Express package manifest: `backup/node-postgres/api/package.json` | NestJS package manifest | VERIFIED — committed in PR #1 | Included in successful NestJS build/test job #113. |
| CHG-004 | Enterprise Group parent branding and subsidiary references | Nexora Group branding in parent/subsidiary pages and documentation | UPDATED — committed in PR #1 | Source changes included in PR #1; visual/live deployment validation remains pending. |
| CHG-005 | Prior deployment and workflow configuration | Updated Docker/GitHub Actions configuration for the two foundations | VERIFIED — committed in PR #1 | Both Compose configurations validated and primary/standby Docker image builds passed in CI run #113. |
| CHG-006 | CI result previously pending | Recorded successful dual-architecture CI run #113 | VERIFIED | [Nexora Dual Architecture CI #113](https://github.com/netanywhere254-beep/Nexcorp/actions/runs/44106165728): all five jobs passed. |
| CHG-007 | Organization membership without centralized authorization rules | Active-organization middleware, organization role policy, explicit policy registration, and tenant authorization feature tests | UPDATED — awaiting CI | Middleware verifies the signed-in active user, selected organization, active organization status, and active membership on each protected route. Policy defines owner/admin/manager/billing/support/member capabilities. CI run is pending. |

## Architecture status

- **Primary:** Laravel 13 + MySQL.
- **Standby:** Next.js + NestJS + PostgreSQL (Redis where configured).
- The standby is a separate technical foundation, not yet a synchronized replica or automatic failover system.
- Commercial product workflows, production deployment, database replication/conversion, restoration and failover testing remain outstanding.

## Sign-off convention

- **UPDATED** means the replacement source has been committed to the implementation branch.
- **VERIFIED** means the relevant automated checks or manual tests passed and a run/result is recorded.
- **DEPLOYED** means the change is running in the intended environment and has been checked there.
- Do not use one status as a substitute for another.

## Archive policy

Superseded source snapshots are kept under `archive/replaced-code/YYYY-MM-DD/`. Never overwrite historical snapshots; create a new dated subfolder for each subsequent replacement. See [archive README](../archive/replaced-code/2026-11-17/README.md).
