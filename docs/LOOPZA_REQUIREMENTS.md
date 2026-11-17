# Loopza — Product Requirements

**Document status:** Initial scope; requirements need validation before implementation.

## 1. Product goal
Provide a SaaS workspace where businesses can plan, coordinate, manage, and measure digital advertising, with self-service tools and optional managed-advertising support.

## 2. Primary user roles
- Platform administrator: manages platform configuration, subscriptions, and support.
- Business owner: manages the business workspace, billing, and team access.
- Campaign manager: plans and monitors campaigns within assigned permissions.
- Managed-service operator: supports campaigns when a customer has selected a managed service.

## 3. MVP capability groups
1. Business registration, workspaces, and team permissions.
2. Business profile, country, currency, and timezone settings.
3. Campaign briefs, objectives, budgets, audiences, and schedules.
4. Campaign workflow states, approvals, and activity history.
5. Basic performance reporting and campaign notes.
6. Subscription plans and service entitlements.
7. Optional managed-service workflow alongside self-service.

## 4. Integration roadmap
- Meta advertising services (Facebook and Instagram).
- Google Ads and YouTube-related campaign workflows where supported.
- TikTok advertising services.

Each integration must be checked against current provider APIs, review requirements, permissions, regional availability, and commercial terms. Do not promise publishing, editing, or reporting capabilities until the required access is approved and tested.

## 5. Later capability groups
- Cross-channel campaign dashboards and consolidated reporting.
- Country-specific plans, currencies, and service configuration.
- AI-assisted campaign drafts and optimization suggestions with user approval.
- Agency or partner workspaces and client collaboration.
- Maintenance controls for individual channels and platform modules.

## 6. Security and operational constraints
- Store provider tokens securely and request the minimum required scopes.
- Clearly show which channels are connected, disconnected, or restricted.
- Require user confirmation before publishing or making material budget changes.
- Keep customer workspaces and campaign data isolated.
- Maintain audit history for account, campaign, and billing actions.

## 7. MVP acceptance checklist
- [ ] A business can create a workspace and invite authorized team members.
- [ ] A user can create and save a campaign brief without connecting an ad account.
- [ ] Campaign status and approvals are visible to authorized workspace members.
- [ ] Subscription entitlements control access to paid features.
- [ ] A connected channel clearly reports its permission and connection state.
- [ ] Workspace isolation and permission boundaries have automated tests.

## 8. Open decisions
- [ ] Select the first country and launch currency.
- [ ] Choose the first advertising channel integration based on API access and customer value.
- [ ] Define subscription tiers and managed-service pricing.
- [ ] Confirm whether billing is shared with NexISP or independently implemented.
- [ ] Define data retention, reporting refresh frequency, and consent requirements.
