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
