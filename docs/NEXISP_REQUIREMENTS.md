# NexISP — Product Requirements

**Document status:** Initial scope; requirements need validation before implementation.
**Pilot organization:** NetPulse WiFi.

## 1. Product goal
Provide a multi-tenant platform for internet service providers to manage customers, connectivity services, subscriptions, billing, payments, network equipment, and support operations.

## 2. Primary user roles
- Platform owner / super administrator: manages the platform and tenant lifecycle.
- ISP owner: manages their own ISP organization, settings, staff, and services.
- ISP staff: performs permitted customer, billing, and support tasks.
- Network operator: manages supported network devices and service provisioning.
- Customer: views their account, package, invoices, payment history, and support options.

## 3. MVP capability groups
1. Tenant onboarding and strict tenant data isolation.
2. Staff accounts, roles, permissions, and audit history.
3. Customer profiles and service addresses.
4. Internet packages, prices, validity, and service status.
5. Subscriptions, renewals, suspensions, and reactivation.
6. Invoices, receipts, balances, and payment reconciliation.
7. Initial MikroTik integration design, with safe configuration previews before applying changes.
8. Customer notifications and basic support records.

## 4. Later capability groups
- Additional router vendors and possible OpenWrt-based integrations.
- Automated provisioning, usage reporting, and network health monitoring.
- Payment provider expansion and supported Kenyan payment workflows.
- AI-assisted troubleshooting with customer consent and human escalation.
- Public APIs, webhooks, reseller support, and advanced reporting.

## 5. Important constraints
- Pochi la Biashara integration must be verified against available official APIs and business eligibility; do not assume direct merchant-pouch automation is supported.
- Never store router credentials or payment secrets in source code.
- Network changes must be permission-controlled, logged, and designed with rollback or recovery procedures.
- A tenant must never access another tenant's customer, billing, or network data.

## 6. MVP acceptance checklist
- [ ] A platform administrator can create and configure an ISP tenant.
- [ ] An ISP can manage its own staff and customers.
- [ ] Staff can create packages and customer subscriptions within their permissions.
- [ ] Billing records and payment status can be reconciled and audited.
- [ ] A customer can view their own account and payment information.
- [ ] Tenant isolation and permission boundaries have automated tests.
- [ ] MikroTik integration is tested in a non-production lab before live use.

## 7. Open decisions
- [ ] Confirm first supported MikroTik models and RouterOS versions.
- [ ] Confirm initial payment provider and supported transaction flow.
- [ ] Define whether customer self-service is included in the first release.
- [ ] Confirm hosting, backup, and data-retention requirements.
- [ ] Prioritize the first NetPulse pilot workflows.


## 8. MikroTik PPPoE service reference

A NestJS/TypeScript reference implementation is stored at [NEXISP_MIKROTIK_NESTJS_SERVICE_REFERENCE.ts](NEXISP_MIKROTIK_NESTJS_SERVICE_REFERENCE.ts). It demonstrates:
- Creating a PPPoE secret with a selected RouterOS profile.
- Finding and disconnecting active PPP sessions for a username.
- Releasing the API connection in a `finally` block and returning safe public errors.
- Avoiding raw error logging and documenting server-side credential handling.

**Implementation boundary:** this is reference code, not an active NexISP endpoint. The repository's current application direction is Laravel + MySQL. Do not wire in a NestJS service unless a separate Node.js integration service is approved. Before production use, add tenant authorization, audit events, secret-manager integration, rate limits, RouterOS version compatibility tests, and lab-router tests. Restrict RouterOS API access to a private management network/VPN and firewall allowlist; never expose it publicly.


## 9. M-Pesa Daraja STK Push service reference

A NestJS/TypeScript reference implementation is stored at [NEXISP_MPESA_NESTJS_SERVICE_REFERENCE.ts](NEXISP_MPESA_NESTJS_SERVICE_REFERENCE.ts). It demonstrates:
- Daraja OAuth access-token retrieval with short-lived in-memory token caching.
- Sandbox/production base URL selection through configuration.
- East Africa Time timestamp generation.
- Kenyan phone-number normalization and validation.
- Positive whole-KES amount and short account-reference validation.
- STK Push initiation with a required HTTPS callback URL and request timeouts.
- Safe error responses without logging credentials or raw provider payloads.

**Implementation boundary:** this is reference code, not an active NexISP payment endpoint. The repository's current application direction remains Laravel + MySQL. Do not wire in a NestJS service unless a separate Node.js integration service is approved. Install and pin a supported Axios version and validate the service against the current Daraja API documentation and sandbox before production use.

**Critical payment lifecycle:** the STK Push response only confirms that a request was accepted for processing. It does not confirm that money was received. The application must persist the pending transaction and Daraja request IDs, implement a callback endpoint, validate and reconcile callback results idempotently, and only then mark the payment successful and activate or renew the customer's service. Never put real credentials in Git or expose them to client applications.


## 10. Container infrastructure reference

A Docker Compose reference stack is stored under `infra/`:
- [docker-compose.nestjs-reference.yml](../infra/docker-compose.nestjs-reference.yml) defines PostgreSQL 15, Redis 7, and a NestJS API container.
- [.env.nexisp.example](../infra/.env.nexisp.example) provides placeholders for local configuration; real secrets are excluded by `infra/.gitignore`.
- [infra/README.md](../infra/README.md) documents configuration, startup, shutdown, and operational safeguards.

The reference improves on the supplied draft by removing hard-coded credentials, requiring environment-provided secrets, adding PostgreSQL/Redis health checks and persistent volumes, requiring Redis authentication, avoiding published PostgreSQL/Redis host ports, binding the API to localhost by default, and waiting for healthy dependencies.

**Implementation boundary:** this is an optional NestJS + PostgreSQL + Redis reference stack. It is not the active Laravel + MySQL deployment and is not ready to run until a compatible NestJS backend and `backend/Dockerfile` exist. Architecture selection must be resolved before production deployment. A Docker volume is not a backup; configure and test database backups, TLS reverse proxying, monitoring, restore procedures, and controlled migrations.


## 11. Linux host preparation reference

A Debian/Ubuntu server preparation script is stored at [infra/install-nexisp.sh](../infra/install-nexisp.sh), with usage and security notes in [infra/README.md](../infra/README.md). It installs Docker Engine, Git, and an available Docker Compose package, then enables and starts Docker. It intentionally does not perform an unattended full operating-system upgrade or deploy the application.

This is host preparation only. It does not mean NexISP is deployed or production-ready. The optional NestJS/PostgreSQL/Redis reference stack still requires an approved architecture decision, a working NestJS backend, a Dockerfile, configured secrets, TLS, backups, monitoring, and deployment testing.

## 12. Alternative three-service deployment proposal

The repository includes an optional Compose reference for separate Nexora, NexISP, and Loopza APIs with independent PostgreSQL databases: [docker-compose.nexora-multiservice-reference.yml](../infra/docker-compose.nexora-multiservice-reference.yml). It is a deployment proposal only and does not replace the current Laravel + MySQL direction or the NexISP-only NestJS/PostgreSQL/Redis reference.

The NexISP Prisma schema expects `DATABASE_URL`; the Compose proposal maps `NEXISP_DATABASE_URL` from the host environment into that container variable. The NexISP API and database use their own private network and persistent volume.

The service build directory and Dockerfile are not yet present. This reference is not currently buildable, and it must not be treated as a running or tested NexISP deployment. Architecture approval, implementation, migration review, tenant-isolation tests, backup/restore tests, and security review are required before use.

