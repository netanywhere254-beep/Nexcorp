# Nexora Group — Change and Replacement Register

Last updated: 2026-11-17  
Working branch: `implementation/nexora-group-audit`  
Pull request: [#1](https://github.com/netanywhere254-beep/Nexcorp/pull/1)

This register distinguishes a committed source update from a verified build or working production feature.

## Replacement register

| ID | Previous file / implementation | Current replacement | Status | Verification |
|---|---|---|---|---|
| CHG-001 | `backend/laravel/composer.json` with Laravel Tinker dependency constraint | Updated Laravel dependency manifest on this branch | UPDATED — committed in PR #1 | Dependency install and Laravel migrations/tests were reported passing in the PR workflow summary; re-check latest Actions before release. |
| CHG-002 | Express API: `backup/node-postgres/api/src/server.js` | NestJS TypeScript API under `backup/node-postgres/api/src/` | UPDATED — committed in PR #1 | NestJS build/tests were reported passing in the PR workflow summary; re-check latest Actions before release. |
| CHG-003 | Express package manifest: `backup/node-postgres/api/package.json` | NestJS package manifest | UPDATED — committed in PR #1 | Included in NestJS build/test workflow; re-check latest Actions. |
| CHG-004 | Enterprise Group parent branding and subsidiary references | Nexora Group branding in parent/subsidiary pages and documentation | UPDATED — committed in PR #1 | Source changes are included in PR #1; visual/live deployment validation remains pending. |
| CHG-005 | Prior deployment and workflow configuration | Updated Docker/GitHub Actions configuration for the two foundations | UPDATED — committed in PR #1 | Docker image build was still pending in the last reported status; do not mark Docker validation complete until Actions confirms it. |

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

Superseded source snapshots are kept under `archive/replaced-code/YYYY-MM-DD/`. Never overwrite historical snapshots; create a new dated archive for each subsequent replacement. See [archive README](../archive/replaced-code/2026-11-17/README.md).
