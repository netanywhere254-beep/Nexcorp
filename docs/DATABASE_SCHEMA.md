# Initial Database Schema — Nexcorp

**Status:** Partially implemented. The initial Laravel migration creates users, organizations, organization_members, and audit_logs. Product-specific NexISP and Loopza tables remain proposed and are not yet implemented.
**Database:** MySQL
**Backend direction:** Laravel modular monolith for the first release.

## 1. Architecture decision

Start with one Laravel application and one MySQL database, organized as clearly separated modules:
- **Shared platform:** authentication, organizations, memberships, roles, permissions, product subscriptions, audit events, and shared settings.
- **NexISP module:** ISP tenants, customers, internet plans, service subscriptions, network devices, invoices, payments, and provisioning jobs.
- **Loopza module:** business workspaces, advertising accounts, channel connections, campaigns, campaign events, and reports.

This is a modular monolith, not microservices. It is simpler and cheaper to build, deploy, back up, and maintain initially. Modules should communicate through Laravel services/interfaces so they can be separated later if justified.

Use shared authentication, but keep product data and permissions distinct. Every tenant-owned record must be scoped to its owning organization or workspace. Enforce authorization in backend policies/services, not only in the user interface.

## 2. Shared platform tables

| Table | Important columns | Purpose |
|---|---|---|
| users | id, name, email, phone, password, status | Login identities |
| organizations | id, name, slug, type, status, country_code, timezone | ISP tenants and business organizations |
| organization_members | id, organization_id, user_id, status, joined_at | User membership |
| roles | id, organization_id nullable, name, guard_name | Platform or organization roles |
| permissions | id, name, guard_name | Granular permissions |
| model_has_roles / model_has_permissions | role_id or permission_id, model_type, model_id | Role/permission assignment |
| product_subscriptions | id, organization_id, product_key, plan_key, status, starts_at, ends_at | Product access entitlements |
| audit_logs | id, organization_id nullable, actor_user_id, action, subject_type, subject_id, metadata_json, created_at | Security and operational history |
| notifications | Laravel notification fields | In-app notifications |

Use secure password hashing. Never store plaintext passwords, payment credentials, router secrets, or provider access tokens in ordinary application tables without appropriate encryption and secret-management controls.

## 3. NexISP module tables

All ISP business records should include an ISP tenant key such as isp_organization_id and timestamps where appropriate.

| Table | Important columns | Purpose |
|---|---|---|
| isp_customers | id, isp_organization_id, customer_number, name, phone, email, status | ISP customer accounts |
| isp_service_addresses | id, customer_id, label, address_text, latitude nullable, longitude nullable | Installation/service locations |
| isp_plans | id, isp_organization_id, name, price, currency, validity_seconds, speed_down_kbps, speed_up_kbps, status | Hotspot and home packages |
| isp_subscriptions | id, isp_organization_id, customer_id, plan_id, status, starts_at, expires_at, next_billing_at | Customer service lifecycle |
| isp_network_devices | id, isp_organization_id, name, vendor, model, management_address, status, credential_reference | Router/NAS/OLT inventory |
| isp_device_profiles | id, isp_organization_id, device_id, profile_name, config_json, status | Reviewed device configurations |
| isp_invoices | id, isp_organization_id, customer_id, invoice_number, amount_due, currency, status, due_at, issued_at | Amounts owed |
| isp_invoice_items | id, invoice_id, description, quantity, unit_amount, total_amount | Invoice line items |
| isp_payments | id, isp_organization_id, customer_id, invoice_id nullable, provider, provider_reference, amount, currency, status, paid_at | Payments and reconciliation |
| isp_provisioning_jobs | id, isp_organization_id, subscription_id, device_id nullable, action, status, request_json, result_json, started_at, finished_at | Track network changes safely |

### NexISP data rules
- Customer and invoice numbers should be unique within the relevant ISP organization.
- Store money as integer minor units or precise decimals; never use floating-point arithmetic for financial totals. Document the currency convention.
- Store speeds in explicit units such as kilobits per second.
- Store timestamps in UTC and render them in the organization's configured timezone.
- Payment callbacks must be authenticated, idempotent, and reconciled against provider references.
- Device credentials must not appear in logs, exported configuration, or Git commits.
- Provisioning jobs should support review, retries, audit history, and safe failure/rollback.

## 4. Loopza module tables

Loopza business data should be scoped to an organization/workspace. A workspace may represent a business or agency client space.

| Table | Important columns | Purpose |
|---|---|---|
| loopza_workspaces | id, organization_id, name, country_code, currency, timezone, status | Business workspaces |
| loopza_workspace_members | id, workspace_id, user_id, role, status | Workspace access |
| loopza_channel_connections | id, workspace_id, provider, external_account_id, status, scopes_json, token_reference, expires_at | Connected ad channels |
| loopza_campaigns | id, workspace_id, name, objective, budget_amount, currency, status, starts_at, ends_at | Campaign briefs/workflows |
| loopza_campaign_channels | id, campaign_id, provider, external_campaign_id nullable, status, settings_json | Per-channel execution |
| loopza_campaign_events | id, campaign_id, actor_user_id nullable, event_type, payload_json, created_at | Approval and change history |
| loopza_reports | id, workspace_id, campaign_id nullable, provider, period_start, period_end, metrics_json, fetched_at | Campaign reporting |

### Loopza data rules
- Do not assume a provider API permits every campaign operation. Store connection status and granted scopes, and expose only verified capabilities.
- Require explicit user approval before publishing or materially changing spend/budgets.
- Encrypt provider tokens and keep encryption keys outside the database and repository.
- Keep each workspace's campaigns, accounts, reports, and members isolated.

## 5. Relationships at a glance

```text
users ──< organization_members >── organizations
organizations ──< product_subscriptions
organizations ──< audit_logs

organizations (ISP tenant)
  └──< isp_customers
        ├──< isp_service_addresses
        ├──< isp_subscriptions >── isp_plans
        │     └──< isp_provisioning_jobs >── isp_network_devices
        ├──< isp_invoices ──< isp_invoice_items
        └──< isp_payments

organizations
  └──< loopza_workspaces
        ├──< loopza_workspace_members >── users
        ├──< loopza_channel_connections
        └──< loopza_campaigns
              ├──< loopza_campaign_channels
              ├──< loopza_campaign_events
              └──< loopza_reports
```

## 6. Laravel implementation plan
1. Create migrations for shared identity and organization tables.
2. Add organization membership, role, and permission policies.
3. Add NexISP migrations and model relationships.
4. Add Loopza migrations and model relationships.
5. Add indexes and unique constraints for tenant-scoped identifiers.
6. Add automated tests proving one organization/workspace cannot access another's records.
7. Add factories and seeders for local development.
8. Then build the first user workflows and integrations.

Prefer Laravel migrations, Eloquent models, Form Requests, Policies, service classes, queued jobs, and automated tests. Keep controllers thin. Use database transactions for financial workflows and idempotency keys for external callbacks.

## 7. Open design decisions before migrations
- [ ] Confirm whether one user may belong to multiple ISP organizations and Loopza workspaces.
- [ ] Confirm whether Loopza workspaces are always children of an organization or may be standalone.
- [ ] Choose the Laravel authentication approach and permission package, if any.
- [ ] Confirm currency precision and canonical money storage convention.
- [ ] Confirm whether hotspot vouchers, PPPoE accounts, OLT/ONU inventory, and reseller hierarchies belong in the first NexISP MVP.
- [ ] Review this proposal before generating migrations.

## 8. Important implementation boundary

This is a proposed schema, not a claim that tables or Laravel migrations already exist. It must be reviewed, translated into migrations, and tested before production use.


## 9. Prisma/PostgreSQL reference supplied for NexISP

A separate reference implementation is stored at [NEXISP_PRISMA_SCHEMA_REFERENCE.prisma](NEXISP_PRISMA_SCHEMA_REFERENCE.prisma). It is based on the Prisma schema supplied during project development and includes tenant-scoped relations, decimal money fields, explicit transaction/subscription statuses, and safer credential handling.

**Important:** this is an alternative design reference only. The current repository direction remains Laravel + MySQL. The Prisma file is not wired into the application, and no Prisma migrations or generated client should be used unless the project explicitly approves changing its backend/database stack. Before production use, validate the schema with the selected Prisma version and add automated tenant-isolation tests.


## 10. Prisma/PostgreSQL reference supplied for Loopza

A separate reference schema is stored at [LOOPZA_PRISMA_SCHEMA_REFERENCE.prisma](LOOPZA_PRISMA_SCHEMA_REFERENCE.prisma). It models Loopza users, business workspaces, workspace memberships, subscription plans, business subscription instances, and advertising campaigns. It uses `LOOPZA_DATABASE_URL` and PostgreSQL-specific Decimal fields.

**Important:** this is an alternative implementation reference, not an active database schema. The proposed shared application architecture remains Laravel + MySQL, where Loopza workspaces and campaigns are represented in the shared schema above. Do not create a second production database or run Prisma migrations until the project explicitly approves that change. Keep the shared identity and workspace authorization model consistent if the schema is ever adopted.


## 11. Prisma/PostgreSQL reference supplied for Nexora

A parent-company reference schema is stored at [NEXORA_PRISMA_SCHEMA_REFERENCE.prisma](NEXORA_PRISMA_SCHEMA_REFERENCE.prisma). It models executive accounts and roles, subsidiary records, Decimal revenue summaries with currency codes, and corporate reports linked to an author and optionally to a subsidiary.

**Important:** this is a design reference only. The proposed shared application architecture remains Laravel + MySQL. The Nexora schema is not wired into a live application and must not be migrated into a separate PostgreSQL database unless that architecture is explicitly approved. Executive roles require server-side authorization. The subsidiary revenue field is a summary, not a substitute for a proper financial ledger, payment reconciliation, or audited accounting records.

## 12. Optional three-database Prisma deployment reference

The alternative three-service deployment proposal is documented in [docker-compose.nexora-multiservice-reference.yml](../infra/docker-compose.nexora-multiservice-reference.yml). It provisions separate PostgreSQL databases and API containers for Nexora, NexISP, and Loopza. The example environment file is [infra/.env.nexora-stack.example](../infra/.env.nexora-stack.example).

The database URL mapping is:
- Nexora Prisma service: `NEXORA_DATABASE_URL`.
- NexISP Prisma service: `DATABASE_URL` inside the container; Compose maps the host-side `NEXISP_DATABASE_URL` into it.
- Loopza Prisma service: `LOOPZA_DATABASE_URL`.

Each service has a separate database, credential set, persistent volume, and private network. This improves deployment separation but does not by itself provide shared login, cross-service transactions, consolidated finance, or corporate reporting. Those capabilities require explicit service APIs, identity federation or a shared identity provider, authorization contracts, and reconciliation rules.

**Important:** this is an optional PostgreSQL/Prisma architecture reference only. The current schema decision remains Laravel + MySQL modular monolith. The three application build directories and Dockerfiles are not yet present, and no application container can be built from this Compose reference today. Do not run migrations or deploy the stack until the architecture is formally approved and each service implementation is complete and tested.

## 13. Standby PostgreSQL database boundary

The approved standby alternative has a separate PostgreSQL 16 service defined in [docker-compose.backup.yml](../infra/docker-compose.backup.yml). It belongs only to the Next.js/Node.js standby foundation under backup/node-postgres. It is not connected to the primary Laravel application or its MySQL database.

The primary data model in this document remains Laravel modular monolith + MySQL. The standby PostgreSQL database is an isolated fallback foundation, not a replicated or synchronized production database. The historical [single-backend Compose reference](../infra/docker-compose.master-ecosystem-reference.yml) and three-service Prisma proposal remain reference material. Do not mix these database configurations or assume cross-engine failover exists.


## 14. Current migration implementation boundary

The primary Laravel application now includes an initial migration at `backend/laravel/database/migrations/2026_11_17_000001_create_shared_platform_tables.php` for the shared users, organizations, organization_members, and audit_logs tables. Matching Eloquent models are in `backend/laravel/app/Models`.

This is only the shared foundation. The full NexISP and Loopza tables in this document are still design specifications; do not assume they exist in the live database until corresponding Laravel migrations, models, policies, and tests are committed. The separate PostgreSQL/Prisma schemas remain reference material for the standby architecture and are not applied to the primary MySQL database.
