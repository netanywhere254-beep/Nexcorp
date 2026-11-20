# Nexora Group IT Manager access contract

The IT Manager is a distinct group-level technical appointment, represented by
`group_authorities.authority = GROUP_IT_MANAGER`, with
`scope_type = group` and `scope_key = nexora`.

## Allowed capabilities

The Laravel Gate ability `group.technical` accepts one capability string.
The current allow-list is:

- `users.directory.view`
- `system_health.view`
- `integrations.manage`
- `security_events.view`
- `backups.jobs.manage`
- `deployments.manage`
- `audit_logs.view`

Example:

```php
Gate::authorize('group.technical', 'system_health.view');
```

## Deny-by-default boundaries

Unknown capabilities are denied. The IT Manager does not receive authority to
manage finance, decide governance approvals, appoint or revoke group authorities,
provision or revoke staff approval phones, or view restricted Trace & Find case
contents merely by holding this appointment. Those actions remain behind their
separate role and workflow controls.

The grant is effective only while the user account and the group appointment
are active, the appointment has started, and it has not expired. Tenant-scoped
appointments do not grant group-level technical access.

## Implementation note

This is the reusable authorization contract. Technical administration routes
must call the Gate with the exact capability they require; adding a UI menu or
hiding a button is not authorization. Each new route must have an authorization
test. Backups, deployments, integration secrets and security incident response
must retain their own audit and approval requirements.
